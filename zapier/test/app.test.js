'use strict';

const zapier = require('zapier-platform-core');
const nock = require('nock');
const App = require('../index');

const appTester = zapier.createAppTester(App);
const API = 'https://api.textmeflow.eu';
const authData = { api_key: 'tmf_test_key' };

describe('TextMeFlow Zapier app', () => {
  beforeAll(() => nock.disableNetConnect());
  afterEach(() => nock.cleanAll());
  afterAll(() => nock.enableNetConnect());

  test('authentication test calls /accounts/me with Bearer key', async () => {
    nock(API, { reqheaders: { authorization: 'Bearer tmf_test_key' } })
      .get('/v1/accounts/me')
      .reply(200, { email: 'a@b.be', plan_code: 'free' });
    const res = await appTester(App.authentication.test, { authData });
    expect(res.email).toBe('a@b.be');
  });

  test('invalid key becomes a refresh-auth error', async () => {
    nock(API).get('/v1/accounts/me').reply(401, { error: 'unauthorized' });
    await expect(appTester(App.authentication.test, { authData })).rejects.toThrow(/no longer valid/);
  });

  test('send message normalises the number and posts', async () => {
    const scope = nock(API)
      .post('/v1/messages', { to: '+32470123456', text: 'Hallo', media_url: 'https://x.be/a.png' })
      .reply(202, { message_id: 'abc', status: 'queued' });
    const res = await appTester(App.creates.send_message.operation.perform, {
      authData,
      inputData: { to: '0032 470-12 34 56', text: 'Hallo', media_url: 'https://x.be/a.png' },
    });
    expect(res).toEqual({ id: 'abc', status: 'queued', to: '+32470123456' });
    expect(scope.isDone()).toBe(true);
  });

  test('send message rejects a number without country code', async () => {
    await expect(
      appTester(App.creates.send_message.operation.perform, { authData, inputData: { to: '0470123456', text: 'x' } })
    ).rejects.toThrow(/country code/);
  });

  test('send message treats 429 as throttling', async () => {
    nock(API).post('/v1/messages').reply(429, { error: 'quota_exceeded' });
    await expect(
      appTester(App.creates.send_message.operation.perform, { authData, inputData: { to: '+32470123456', text: 'x' } })
    ).rejects.toThrow(/429/);
  });

  test('find message status returns the status', async () => {
    nock(API).get('/v1/messages/abc').reply(200, { id: 'abc', status: 'sent', to: '+32470123456' });
    const res = await appTester(App.searches.find_message_status.operation.perform, { authData, inputData: { message_id: 'abc' } });
    expect(res[0]).toMatchObject({ id: 'abc', status: 'sent' });
  });

  test('find message status returns empty on 404', async () => {
    nock(API).get('/v1/messages/nope').reply(404, {});
    const res = await appTester(App.searches.find_message_status.operation.perform, { authData, inputData: { message_id: 'nope' } });
    expect(res).toEqual([]);
  });

  test('trigger subscribes, unsubscribes and parses payload', async () => {
    const t = App.triggers.new_incoming_message.operation;
    nock(API).post('/v1/webhooks', { url: 'https://hooks.zapier.com/x', event: 'message.inbound' }).reply(201, { id: 'wh1', url: 'https://hooks.zapier.com/x', event: 'message.inbound', secret: 'whsec_x' });
    const sub = await appTester(t.performSubscribe, { authData, targetUrl: 'https://hooks.zapier.com/x' });
    expect(sub).toEqual({ id: 'wh1' });

    const del = nock(API).delete('/v1/webhooks/wh1').reply(204);
    await appTester(t.performUnsubscribe, { authData, subscribeData: sub });
    expect(del.isDone()).toBe(true);

    const out = await appTester(t.perform, {
      authData,
      rawRequest: { headers: { 'x-textmeflow-event-id': 'evt-1' } },
      cleanedRequest: { type: 'text', from: '32472932208', from_name: 'Jan', to: '32499123456', message: 'Hi', file: 'null' },
    });
    expect(out[0]).toMatchObject({ id: 'evt-1', message: 'Hi', file: null });

    const own = await appTester(t.perform, { authData, cleanedRequest: { from_me: true, message: 'x' } });
    expect(own).toEqual([]);
    const list = await appTester(t.performList, { authData });
    expect(list[0].id).toBeTruthy();
    expect(App.triggers.new_incoming_message.display.hidden).toBeFalsy();
  });
});
