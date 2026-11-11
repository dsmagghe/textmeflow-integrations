import { createHmac, timingSafeEqual } from 'crypto';

import type {
	IDataObject,
	IHookFunctions,
	IWebhookFunctions,
	IWebhookResponseData,
	INodeType,
	INodeTypeDescription,
} from 'n8n-workflow';
import { NodeConnectionTypes } from 'n8n-workflow';

const API_BASE = 'https://api.textmeflow.eu/v1';

const MESSAGE_TYPES = ['text', 'image', 'audio', 'video', 'document', 'location'];

/** Constant-time comparison of two strings. */
function safeEqual(a: string, b: string): boolean {
	const bufA = Buffer.from(a);
	const bufB = Buffer.from(b);
	return bufA.length === bufB.length && timingSafeEqual(bufA, bufB);
}

export class TextMeFlowTrigger implements INodeType {
	description: INodeTypeDescription = {
		displayName: 'TextMeFlow Trigger',
		name: 'textMeFlowTrigger',
		icon: { light: 'file:textmeflow.svg', dark: 'file:textmeflow.dark.svg' },
		group: ['trigger'],
		version: 1,
		subtitle: 'On incoming WhatsApp message',
		description: 'Starts a workflow when someone sends a WhatsApp message to your TextMeFlow number',
		defaults: {
			name: 'TextMeFlow Trigger',
		},
		inputs: [],
		outputs: [NodeConnectionTypes.Main],
		credentials: [
			{
				name: 'textMeFlowApi',
				required: true,
			},
		],
		webhooks: [
			{
				name: 'default',
				httpMethod: 'POST',
				responseMode: 'onReceived',
				path: 'textmeflow',
			},
		],
		properties: [
			{
				displayName:
					'Nothing to copy: when you activate the workflow (or press "Listen for test event"), this node registers its own URL with TextMeFlow and removes it again when you deactivate it. Only the API key in your TextMeFlow credential is needed.',
				name: 'setupNotice',
				type: 'notice',
				default: '',
			},
			{
				displayName: 'Verify Signature',
				name: 'verifySignature',
				type: 'boolean',
				default: true,
				description:
					'Whether to check the X-TextMeFlow-Signature header with the secret TextMeFlow issued when this node registered its URL. Strongly recommended: without it anyone who knows the URL can start your workflow.',
			},
			{
				displayName: 'Options',
				name: 'options',
				type: 'collection',
				placeholder: 'Add Option',
				default: {},
				options: [
					{
						displayName: 'Ignore Duplicates',
						name: 'ignoreDuplicates',
						type: 'boolean',
						default: true,
						description:
							'Whether to ignore a message that TextMeFlow delivers again after a retry (same event ID)',
					},
					{
						displayName: 'Include My Own Messages',
						name: 'includeOwn',
						type: 'boolean',
						default: false,
						description:
							'Whether to also start the workflow for messages you sent yourself (through the API or by hand in WhatsApp). Off by default so a workflow that replies does not trigger itself.',
					},
					{
						displayName: 'Message Types',
						name: 'types',
						type: 'multiOptions',
						options: MESSAGE_TYPES.map((t) => ({
							name: t.charAt(0).toUpperCase() + t.slice(1),
							value: t,
						})),
						default: [],
						description: 'Only start for these message types. Leave empty for all types.',
					},
				],
			},
		],
	};

	// The node registers its own URL through POST /v1/webhooks and removes it
	// through DELETE /v1/webhooks/{id}. The id and the signing secret (only shown
	// once by the API) are kept in the node's static data.
	webhookMethods = {
		default: {
			async checkExists(this: IHookFunctions): Promise<boolean> {
				const staticData = this.getWorkflowStaticData('node');
				const webhookUrl = this.getNodeWebhookUrl('default');
				const response = (await this.helpers.httpRequestWithAuthentication.call(
					this,
					'textMeFlowApi',
					{ method: 'GET', url: `${API_BASE}/webhooks`, json: true },
				)) as { data?: Array<{ id: string; url: string }> };
				const existing = (response.data ?? []).find((w) => w.url === webhookUrl);
				if (existing && staticData.webhookId === existing.id && staticData.webhookSecret) {
					return true;
				}
				if (existing) {
					// Registered, but we lost its secret (shown only once): replace it.
					await this.helpers.httpRequestWithAuthentication.call(this, 'textMeFlowApi', {
						method: 'DELETE',
						url: `${API_BASE}/webhooks/${encodeURIComponent(existing.id)}`,
					});
				}
				delete staticData.webhookId;
				delete staticData.webhookSecret;
				return false;
			},
			async create(this: IHookFunctions): Promise<boolean> {
				const staticData = this.getWorkflowStaticData('node');
				const response = (await this.helpers.httpRequestWithAuthentication.call(
					this,
					'textMeFlowApi',
					{
						method: 'POST',
						url: `${API_BASE}/webhooks`,
						body: { url: this.getNodeWebhookUrl('default'), event: 'message.inbound' },
						json: true,
					},
				)) as { id?: string; secret?: string };
				if (!response.id || !response.secret) {
					return false;
				}
				staticData.webhookId = response.id;
				staticData.webhookSecret = response.secret;
				return true;
			},
			async delete(this: IHookFunctions): Promise<boolean> {
				const staticData = this.getWorkflowStaticData('node');
				const id = staticData.webhookId as string | undefined;
				if (id) {
					try {
						await this.helpers.httpRequestWithAuthentication.call(this, 'textMeFlowApi', {
							method: 'DELETE',
							url: `${API_BASE}/webhooks/${encodeURIComponent(id)}`,
						});
					} catch (error) {
						const status = (error as { httpCode?: string }).httpCode;
						if (status !== '404') {
							return false;
						}
					}
				}
				delete staticData.webhookId;
				delete staticData.webhookSecret;
				return true;
			},
		},
	};

	async webhook(this: IWebhookFunctions): Promise<IWebhookResponseData> {
		const req = this.getRequestObject();
		const res = this.getResponseObject();
		const body = this.getBodyData() as IDataObject;
		const verify = this.getNodeParameter('verifySignature', true) as boolean;
		const options = this.getNodeParameter('options', {}) as {
			ignoreDuplicates?: boolean;
			includeOwn?: boolean;
			types?: string[];
		};

		if (verify) {
			// The secret issued when this node registered its URL; fall back to a
			// secret typed into the credential (older setups via the portal).
			const staticSecret = this.getWorkflowStaticData('node').webhookSecret as string | undefined;
			const credentials = await this.getCredentials<{ webhookSecret?: string }>(
				'textMeFlowApi',
			).catch(() => undefined);
			const secret = staticSecret || credentials?.webhookSecret;

			if (!secret) {
				// Refuse rather than silently accept unsigned traffic.
				res.status(401).send('No webhook secret known for this node');
				return { noWebhookResponse: true };
			}

			const rawBody = req.rawBody;
			const received = String(req.headers['x-textmeflow-signature'] ?? '');
			const expected =
				'sha256=' +
				createHmac('sha256', secret)
					.update(rawBody ?? Buffer.alloc(0))
					.digest('hex');

			if (!rawBody || !safeEqual(expected, received)) {
				res.status(401).send('Invalid signature');
				return { noWebhookResponse: true };
			}
		}

		// Retries reuse the same event id: remember the most recent ones.
		if (options.ignoreDuplicates !== false) {
			const eventId = String(req.headers['x-textmeflow-event-id'] ?? '');
			if (eventId) {
				const staticData = this.getWorkflowStaticData('node');
				const seen = (staticData.seenEventIds as string[] | undefined) ?? [];
				if (seen.includes(eventId)) {
					return { webhookResponse: 'duplicate ignored' };
				}
				staticData.seenEventIds = [...seen, eventId].slice(-200);
			}
		}

		if (!options.includeOwn && body.from_me === true) {
			return { webhookResponse: 'own message ignored' };
		}
		if (options.types?.length && !options.types.includes(String(body.type))) {
			return { webhookResponse: 'type filtered' };
		}

		return {
			workflowData: [this.helpers.returnJsonArray(body)],
		};
	}
}
