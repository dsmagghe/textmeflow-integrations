{**
 * Copyright (c) Fades Management EOOD (TextMeFlow)
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    TextMeFlow <info@fades.tech>
 * @copyright Fades Management EOOD
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License (AFL 3.0)
 *}
<div id="tmf-app" class="tmf" data-ajax-url="{$tmf_ajax_url|escape:'html':'UTF-8'}" data-strings="{$tmf_js_strings|escape:'html':'UTF-8'}" data-connected="{if $tmf_connected}1{else}0{/if}">

  {if !$tmf_connected}
    <div class="panel tmf-hero">
      <div class="panel-heading">{l s='TextMeFlow - WhatsApp order updates' d='Modules.Textmeflow.Admin'}</div>
      <p class="tmf-lead">{l s='Send your customers order updates on WhatsApp, from your own number. Setting up takes about two minutes.' d='Modules.Textmeflow.Admin'}</p>
      <ol class="tmf-steps">
        <li>{l s='Click the button below and sign in or create your free TextMeFlow account.' d='Modules.Textmeflow.Admin'}</li>
        <li>{l s='Allow the connection and you return here automatically.' d='Modules.Textmeflow.Admin'}</li>
        <li>{l s='Scan the QR code with WhatsApp. Done.' d='Modules.Textmeflow.Admin'}</li>
      </ol>
      <form method="post" action="{$tmf_form_url|escape:'html':'UTF-8'}">
        <button type="submit" name="tmf_connect" value="1" class="btn btn-primary btn-lg">{l s='Connect with TextMeFlow' d='Modules.Textmeflow.Admin'}</button>
      </form>
      <p class="help-block tmf-privacy">{l s='When you connect, the phone number and message text of the messages you send are processed by TextMeFlow (EU-hosted, Fades Management EOOD, Bulgaria).' d='Modules.Textmeflow.Admin'}
        <a href="https://textmeflow.eu/legal/privacy" target="_blank" rel="noopener noreferrer">{l s='Privacy policy' d='Modules.Textmeflow.Admin'}</a> |
        <a href="https://textmeflow.eu/legal/terms" target="_blank" rel="noopener noreferrer">{l s='Terms' d='Modules.Textmeflow.Admin'}</a></p>
    </div>
  {else}
    <div class="panel">
      <div class="panel-heading">{l s='WhatsApp connection' d='Modules.Textmeflow.Admin'}</div>
      <p id="tmf-status" class="tmf-status">{l s='Checking...' d='Modules.Textmeflow.Admin'}</p>
      <div id="tmf-pair" class="tmf-pair" hidden>
        <img id="tmf-qr" alt="{l s='QR code to link WhatsApp' d='Modules.Textmeflow.Admin'}" width="240" height="240" hidden>
        <p id="tmf-pair-msg" class="help-block"></p>
        <p id="tmf-upgrade" hidden><a class="btn btn-default" href="https://textmeflow.eu/pricing" target="_blank" rel="noopener noreferrer">{l s='Upgrade your plan' d='Modules.Textmeflow.Admin'}</a></p>
      </div>
      <p>
        <button type="button" id="tmf-pair-btn" class="btn btn-primary" hidden>{l s='Show QR code' d='Modules.Textmeflow.Admin'}</button>
      </p>
    </div>

    <form method="post" action="{$tmf_form_url|escape:'html':'UTF-8'}" class="panel" id="tmf-settings">
      <div class="panel-heading">{l s='Messages' d='Modules.Textmeflow.Admin'}</div>
      <p class="help-block">{l s='Customers only receive a message when they ticked the WhatsApp box when ordering. Leave a text empty to use the ready-made text in the language of the customer. You can use these placeholders:' d='Modules.Textmeflow.Admin'}
        <code>{foreach from=$tmf_placeholders item=ph name=phl}{$ph|escape:'html':'UTF-8'}{if !$smarty.foreach.phl.last} {/if}{/foreach}</code></p>
      <div class="form-group tmf-own-number">
        <label for="tmf_merchant_phone">{l s='Your own mobile number' d='Modules.Textmeflow.Admin'}</label>
        <div class="input-group">
          <input type="text" id="tmf_merchant_phone" name="tmf_merchant_phone" class="form-control" value="{$tmf_merchant_phone|escape:'html':'UTF-8'}" placeholder="+32 470 12 34 56" maxlength="25">
          <span class="input-group-btn"><button type="button" id="tmf-test-btn" class="btn btn-default">{l s='Send me a test message' d='Modules.Textmeflow.Admin'}</button></span>
        </div>
        <span id="tmf-test-msg" class="tmf-test-msg" role="status"></span>
        <p class="help-block">{l s='Used for the test message and, if you tick the box below, for new-order messages to you.' d='Modules.Textmeflow.Admin'}</p>
      </div>
      <div class="checkbox">
        <label><input type="checkbox" name="tmf_notify" value="1" {if $tmf_notify}checked{/if}> {l s='Send me a WhatsApp message for every new order' d='Modules.Textmeflow.Admin'}</label>
      </div>
      <table class="table tmf-table">
        <thead><tr>
          <th>{l s='Order status' d='Modules.Textmeflow.Admin'}</th>
          <th>{l s='Send a message' d='Modules.Textmeflow.Admin'}</th>
          <th>{l s='Own text (optional)' d='Modules.Textmeflow.Admin'}</th>
        </tr></thead>
        <tbody>
        {foreach from=$tmf_statuses item=st}
          <tr>
            <td>{$st.name|escape:'html':'UTF-8'}</td>
            <td><input type="checkbox" name="tmf_status[{$st.id|intval}][enabled]" value="1" {if $st.enabled}checked{/if}></td>
            <td><textarea name="tmf_status[{$st.id|intval}][text]" rows="2" maxlength="1000" class="form-control">{$st.text|escape:'html':'UTF-8'}</textarea></td>
          </tr>
        {/foreach}
        </tbody>
      </table>

      <div class="panel-footer">
        <button type="submit" name="tmf_save" value="1" class="btn btn-primary pull-right">{l s='Save' d='Modules.Textmeflow.Admin'}</button>
      </div>
    </form>

    <form method="post" action="{$tmf_form_url|escape:'html':'UTF-8'}" class="panel" onsubmit="return confirm('{l s='Remove the connection with TextMeFlow?' d='Modules.Textmeflow.Admin' js=1}');">
      <div class="panel-heading">{l s='Disconnect' d='Modules.Textmeflow.Admin'}</div>
      <p class="help-block">{l s='No more messages will be sent until you connect again.' d='Modules.Textmeflow.Admin'}</p>
      <button type="submit" name="tmf_disconnect" value="1" class="btn btn-default">{l s='Disconnect' d='Modules.Textmeflow.Admin'}</button>
    </form>
  {/if}
</div>
