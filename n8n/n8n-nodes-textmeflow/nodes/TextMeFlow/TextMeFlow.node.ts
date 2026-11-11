import type {
	IDataObject,
	IExecuteFunctions,
	JsonObject,
	INodeExecutionData,
	INodeProperties,
	INodeType,
	INodeTypeDescription,
} from 'n8n-workflow';
import { NodeApiError, NodeConnectionTypes, NodeOperationError } from 'n8n-workflow';

import { normalizePhoneNumber } from './GenericFunctions';

const API_BASE_URL = 'https://api.textmeflow.eu';

const COUNTRY_OPTIONS: INodeProperties['options'] = [
	{ name: 'Austria', value: 'AT' },
	{ name: 'Belgium', value: 'BE' },
	{ name: 'Bulgaria', value: 'BG' },
	{ name: 'Croatia', value: 'HR' },
	{ name: 'Cyprus', value: 'CY' },
	{ name: 'Czechia', value: 'CZ' },
	{ name: 'Denmark', value: 'DK' },
	{ name: 'Estonia', value: 'EE' },
	{ name: 'Finland', value: 'FI' },
	{ name: 'France', value: 'FR' },
	{ name: 'Germany', value: 'DE' },
	{ name: 'Greece', value: 'GR' },
	{ name: 'Hungary', value: 'HU' },
	{ name: 'Iceland', value: 'IS' },
	{ name: 'Ireland', value: 'IE' },
	{ name: 'Italy', value: 'IT' },
	{ name: 'Latvia', value: 'LV' },
	{ name: 'Liechtenstein', value: 'LI' },
	{ name: 'Lithuania', value: 'LT' },
	{ name: 'Luxembourg', value: 'LU' },
	{ name: 'Malta', value: 'MT' },
	{ name: 'Netherlands', value: 'NL' },
	{ name: 'Norway', value: 'NO' },
	{ name: 'Poland', value: 'PL' },
	{ name: 'Portugal', value: 'PT' },
	{ name: 'Romania', value: 'RO' },
	{ name: 'Slovakia', value: 'SK' },
	{ name: 'Slovenia', value: 'SI' },
	{ name: 'Spain', value: 'ES' },
	{ name: 'Sweden', value: 'SE' },
	{ name: 'Switzerland', value: 'CH' },
	{ name: 'United Kingdom', value: 'GB' },
	{ name: 'United States', value: 'US' },
];

export class TextMeFlow implements INodeType {
	description: INodeTypeDescription = {
		displayName: 'TextMeFlow',
		name: 'textMeFlow',
		icon: { light: 'file:textmeflow.svg', dark: 'file:textmeflow.dark.svg' },
		group: ['output'],
		version: 1,
		subtitle: '={{$parameter["operation"]}}',
		description: 'Send WhatsApp messages from your own number with TextMeFlow (EU-hosted)',
		defaults: {
			name: 'TextMeFlow',
		},
		usableAsTool: true,
		inputs: [NodeConnectionTypes.Main],
		outputs: [NodeConnectionTypes.Main],
		credentials: [
			{
				name: 'textMeFlowApi',
				required: true,
			},
		],
		properties: [
			{
				displayName:
					'Messages are sent from the WhatsApp number you linked in your TextMeFlow portal. Only message people who agreed to receive WhatsApp messages from you.',
				name: 'notice',
				type: 'notice',
				default: '',
			},
			{
				displayName: 'Resource',
				name: 'resource',
				type: 'options',
				noDataExpression: true,
				options: [{ name: 'Message', value: 'message' }],
				default: 'message',
			},
			{
				displayName: 'Operation',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				displayOptions: { show: { resource: ['message'] } },
				options: [
					{
						name: 'Get Status',
						value: 'getStatus',
						description: 'Check whether a sent message was delivered or read',
						action: 'Get message status',
					},
					{
						name: 'Send',
						value: 'send',
						description: 'Send a WhatsApp message',
						action: 'Send a message',
					},
				],
				default: 'send',
			},

			// ----- Send -----
			{
				displayName: 'To',
				name: 'to',
				type: 'string',
				required: true,
				default: '',
				placeholder: '+32470123456',
				displayOptions: { show: { resource: ['message'], operation: ['send'] } },
				description:
					'Phone number of the recipient. Best in international format, for example +32470123456. Spaces and dashes are removed for you.',
			},
			{
				displayName: 'Message',
				name: 'text',
				type: 'string',
				typeOptions: { rows: 4 },
				required: true,
				default: '',
				displayOptions: { show: { resource: ['message'], operation: ['send'] } },
				description: 'The text of the message',
			},
			{
				displayName: 'Additional Fields',
				name: 'additionalFields',
				type: 'collection',
				placeholder: 'Add Field',
				default: {},
				displayOptions: { show: { resource: ['message'], operation: ['send'] } },
				options: [
					{
						displayName: 'Default Country',
						name: 'country',
						type: 'options',
						options: COUNTRY_OPTIONS,
						default: 'BE',
						description:
							'Used when the number is written the national way (for example 0470 12 34 56): the leading 0 is replaced by this country code. Ignored for numbers that start with + or 00.',
					},
					{
						displayName: 'Media URL',
						name: 'mediaUrl',
						type: 'string',
						default: '',
						placeholder: 'https://example.com/photo.jpg',
						description:
							'Public https link to an image or file to send along with the message',
					},
				],
			},

			// ----- Get status -----
			{
				displayName: 'Message ID',
				name: 'messageId',
				type: 'string',
				required: true,
				default: '',
				displayOptions: { show: { resource: ['message'], operation: ['getStatus'] } },
				description:
					'The message_id returned by the Send operation. Drag it in from the previous node.',
			},
		],
	};

	async execute(this: IExecuteFunctions): Promise<INodeExecutionData[][]> {
		const items = this.getInputData();
		const returnData: INodeExecutionData[] = [];
		const operation = this.getNodeParameter('operation', 0) as string;

		for (let i = 0; i < items.length; i++) {
			try {
				let response: IDataObject;

				if (operation === 'send') {
					const to = (this.getNodeParameter('to', i) as string).trim();
					const text = this.getNodeParameter('text', i) as string;
					const additional = this.getNodeParameter('additionalFields', i, {}) as {
						country?: string;
						mediaUrl?: string;
					};

					const normalized = normalizePhoneNumber(to, additional.country);
					if (!/^\+\d{6,15}$/.test(normalized)) {
						throw new NodeOperationError(
							this.getNode(),
							`"${to}" is not a usable phone number`,
							{
								itemIndex: i,
								description:
									'Write the number with country code, for example +32470123456, or pick a Default Country under Additional Fields.',
							},
						);
					}

					const body: IDataObject = { to: normalized, text };
					if (additional.mediaUrl) {
						body.media_url = additional.mediaUrl;
					}

					response = (await this.helpers.httpRequestWithAuthentication.call(
						this,
						'textMeFlowApi',
						{
							method: 'POST',
							url: `${API_BASE_URL}/v1/messages`,
							body,
							json: true,
						},
					)) as IDataObject;
				} else {
					const messageId = this.getNodeParameter('messageId', i) as string;
					response = (await this.helpers.httpRequestWithAuthentication.call(
						this,
						'textMeFlowApi',
						{
							method: 'GET',
							url: `${API_BASE_URL}/v1/messages/${encodeURIComponent(messageId)}`,
							json: true,
						},
					)) as IDataObject;
				}

				returnData.push({ json: response, pairedItem: { item: i } });
			} catch (error) {
				if (this.continueOnFail()) {
					returnData.push({
						json: { error: (error as Error).message },
						pairedItem: { item: i },
					});
					continue;
				}
				if (error instanceof NodeOperationError) {
					throw new NodeOperationError(this.getNode(), error, { itemIndex: i });
				}
				throw new NodeApiError(this.getNode(), error as JsonObject, { itemIndex: i });
			}
		}

		return [returnData];
	}
}
