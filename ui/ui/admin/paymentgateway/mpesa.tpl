{include file="sections/header.tpl"}
<div class="row">
    <div class="col-md-8 col-md-offset-2">
        <form method="post" action="{Text::url('paymentgateway/mpesa')}" autocomplete="off">
            <input type="hidden" name="csrf_token" value="{$csrf_token}">
            <div class="panel panel-primary">
                <div class="panel-heading">Safaricom M-Pesa STK Push</div>
                <div class="panel-body">
                    <div class="form-group">
                        <label for="environment">Environment</label>
                        <select class="form-control" id="environment" name="environment">
                            <option value="sandbox" {if $mpesa.environment eq 'sandbox'}selected{/if}>Sandbox</option>
                            <option value="production" {if $mpesa.environment eq 'production'}selected{/if}>Production</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="consumer_key">Consumer Key</label>
                        <input class="form-control" type="password" id="consumer_key" name="consumer_key" autocomplete="new-password">
                        <span class="help-block">{if $mpesa.consumer_key_set}A key is saved. Leave blank to keep it.{else}Enter the Daraja app consumer key.{/if}</span>
                    </div>
                    <div class="form-group">
                        <label for="consumer_secret">Consumer Secret</label>
                        <input class="form-control" type="password" id="consumer_secret" name="consumer_secret" autocomplete="new-password">
                        <span class="help-block">{if $mpesa.consumer_secret_set}A secret is saved. Leave blank to keep it.{else}Enter the Daraja app consumer secret.{/if}</span>
                    </div>
                    <div class="form-group">
                        <label for="shortcode">Business Short Code</label>
                        <input class="form-control" type="text" id="shortcode" name="shortcode" value="{$mpesa.shortcode|escape}">
                        <span class="help-block">Use the shortcode issued for your selected Daraja environment.</span>
                    </div>
                    <div class="form-group">
                        <label for="passkey">LNM Passkey</label>
                        <input class="form-control" type="password" id="passkey" name="passkey" autocomplete="new-password">
                        <span class="help-block">{if $mpesa.passkey_set}A passkey is saved. Leave blank to keep it.{else}Enter the Lipa Na M-Pesa Online passkey.{/if}</span>
                    </div>
                    <div class="form-group">
                        <label for="country_code">Default Phone Country Code</label>
                        <input class="form-control" type="text" id="country_code" name="country_code" value="{$mpesa.country_code|escape}" pattern="[0-9]+" required>
                        <span class="help-block">Kenya: 254. Customer phone numbers are read from their PHPNuxBill profile.</span>
                    </div>
                    <div class="form-group">
                        <label for="transaction_type">Transaction Type</label>
                        <select class="form-control" id="transaction_type" name="transaction_type">
                            <option value="CustomerPayBillOnline" {if $mpesa.transaction_type eq 'CustomerPayBillOnline'}selected{/if}>Pay Bill</option>
                            <option value="CustomerBuyGoodsOnline" {if $mpesa.transaction_type eq 'CustomerBuyGoodsOnline'}selected{/if}>Buy Goods / Till</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="callback_url">Callback URL</label>
                        <input class="form-control" type="url" id="callback_url" name="callback_url" value="{$mpesa.callback_url|escape}" placeholder="{$mpesa.callback_default|escape}">
                        <span class="help-block">Leave blank to use the displayed app URL. Safaricom must be able to reach this URL over public HTTPS; localhost is not reachable by Daraja.</span>
                    </div>
                </div>
                <div class="panel-footer">
                    <button class="btn btn-primary" type="submit">Save M-Pesa Settings</button>
                    <a class="btn btn-default" href="{Text::url('paymentgateway')}">Back to Gateways</a>
                </div>
            </div>
        </form>
    </div>
</div>
{include file="sections/footer.tpl"}