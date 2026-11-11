import type {
	IAuthenticateGeneric,
	ICredentialTestRequest,
	ICredentialType,
	INodeProperties,
} from 'n8n-workflow';

export class TextMeFlowApi implements ICredentialType {
	name = 'textMeFlowApi';

	displayName = 'TextMeFlow API';

	icon = {
		light: 'file:../nodes/TextMeFlow/textmeflow.svg',
		dark: 'file:../nodes/TextMeFlow/textmeflow.dark.svg',
	} as const;

	documentationUrl = 'https://textmeflow.eu/docs/getting-started';

	properties: INodeProperties[] = [
		{
			displayName:
				'<b>Where do I find my API key?</b><br>1. Log in at <a href="https://textmeflow.eu/login" target="_blank">textmeflow.eu/login</a> (no password needed: you get a sign-in link by email). No account yet? <a href="https://textmeflow.eu/signup" target="_blank">Create a free one</a>.<br>2. Open <a href="https://textmeflow.eu/admin/portal" target="_blank">textmeflow.eu/admin/portal</a>. Your API key is shown there. Copy it.<br>3. Paste it in the field below.<br>4. Not linked to WhatsApp yet? Scan the QR code on the same page (WhatsApp, Settings, Linked devices).',
			name: 'notice',
			type: 'notice',
			default: '',
		},
		{
			displayName: 'API Key',
			name: 'apiKey',
			type: 'string',
			typeOptions: { password: true },
			default: '',
			required: true,
			description: 'The API key from your TextMeFlow portal. It starts with "tmf_".',
		},
		{
			displayName: 'Webhook Secret',
			name: 'webhookSecret',
			type: 'string',
			typeOptions: { password: true },
			default: '',
			description:
				'Optional. The TextMeFlow Trigger registers its own URL and keeps its own secret, so leave this empty. Only fill it in if you set the trigger URL by hand in the portal (textmeflow.eu/admin/webhook); it is then used as a fallback to verify incoming messages.',
		},
	];

	authenticate: IAuthenticateGeneric = {
		type: 'generic',
		properties: {
			headers: {
				Authorization: '=Bearer {{$credentials.apiKey}}',
			},
		},
	};

	test: ICredentialTestRequest = {
		request: {
			baseURL: 'https://api.textmeflow.eu',
			url: '/v1/accounts/me',
			method: 'GET',
		},
	};
}
