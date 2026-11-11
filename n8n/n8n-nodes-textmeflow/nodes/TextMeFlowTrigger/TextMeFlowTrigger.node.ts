import { createHmac, timingSafeEqual } from 'crypto';

import type {
	IDataObject,
	IWebhookFunctions,
	IWebhookResponseData,
	INodeType,
	INodeTypeDescription,
} from 'n8n-workflow';
import { NodeConnectionTypes } from 'n8n-workflow';

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
				required: false,
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
					'<b>How to connect (one time):</b><br>1. Copy the Webhook URL above (use the Production URL once the workflow is active; use the Test URL while you press "Listen for test event").<br>2. Open <a href="https://textmeflow.eu/admin/webhook" target="_blank">textmeflow.eu/admin/webhook</a>, paste the URL, and click Save.<br>3. TextMeFlow now shows a secret. Copy it into the "Webhook Secret" field of your TextMeFlow API credential, so this node can check that messages are genuine.<br>4. Click "Test fire" in the portal to send a sample message.',
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
					'Whether to check the X-TextMeFlow-Signature header with your webhook secret. Strongly recommended: without it anyone who knows the URL can start your workflow.',
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

	// The webhook URL cannot be registered through the TextMeFlow API: the user
	// pastes it in the portal (textmeflow.eu/admin/webhook). These hooks are
	// therefore deliberate no-ops that report "already registered".
	webhookMethods = {
		default: {
			async checkExists(): Promise<boolean> {
				return true;
			},
			async create(): Promise<boolean> {
				return true;
			},
			async delete(): Promise<boolean> {
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
			const credentials = await this.getCredentials<{ webhookSecret?: string }>(
				'textMeFlowApi',
			).catch(() => undefined);
			const secret = credentials?.webhookSecret;

			if (!secret) {
				// Refuse rather than silently accept unsigned traffic.
				res.status(401).send('Webhook secret not configured in the TextMeFlow API credential');
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
