<div class="alert alert-block alert-info">
    <p>{$LANG.domainname}: <strong>{$domain}</strong></p>
</div>

{if $error}
<div class="alert alert-error textcenter">
    {$error}
</div>
{else}
    {if $DSRecords eq 'YES'}
        <p style="font-size: 110%; text-align: center"><b>DS records:</b><br /></p>
        {foreach $DSRecordslist as $item}
            <div class="textcenter">
            <form method="post" action="clientarea.php">
            <input type="hidden" name="action" value="domaindetails" />
            <input type="hidden" name="id" value="{$domainid}" />
            <input type="hidden" name="modop" value="custom" />
            <input type="hidden" name="a" value="manageDNSSECDSRecords" />
            <input type="hidden" name="command" value="secDNSrem" />

            <div>
            Key tag: <input name="keyTag" type="text" maxlength="65535" data-supported="True" data-required="True" value="{$item.keyTag}" />
            Algorithm: <input name="alg" data-supported="True" data-required="True" value="{$item.alg}">
            Digest type: <input name="digestType" data-supported="True" data-required="True" value="{$item.digestType}">
            Digest: <input name="digest" data-supported="True" data-required="True" value="{$item.digest}">
            </div>

            <p class="text-center">
            <input type="submit" class="btn btn-primary" value="Remove DS Record" />
            </p>
            </form>
            </div>
            <br />
        {/foreach}
    {else}
        <p style="font-size: 200%; text-align: center; background: #EEE; padding: 5px">{$DSRecords}</p>
    {/if}
{/if}

<hr>
<div class="textcenter">
<form method="post" action="clientarea.php">
<input type="hidden" name="action" value="domaindetails" />
<input type="hidden" name="id" value="{$domainid}" />
<input type="hidden" name="modop" value="custom" />
<input type="hidden" name="a" value="manageDNSSECDSRecords" />
<input type="hidden" name="command" value="secDNSadd" />

<div>
Key tag: <input name="keyTag" type="text" maxlength="65535" data-supported="True" data-required="True" data-previousvalue="" />

Algorithm: <select name="alg" data-supported="True" data-required="True" data-previousvalue="">
        <option value="5">5 (RSA/SHA-1)</option>
        <option value="6">6 (DSA-NSEC3-SHA1)</option>
        <option value="7">7 (RSASHA1-NSEC3-SHA1)</option>
        <option value="8">8 (RSA/SHA-256)</option>
        <option value="10">10 (RSA/SHA-512)</option>
        <option value="13">13 (ECDSA Curve P-256 with SHA-256)</option>
    </select>

Digest type: <select name="digestType" data-supported="True" data-required="True" data-previousvalue="">
        <option value="1">1 (SHA-1)</option>
        <option value="2">2 (SHA-256)</option>
    </select>
</div>

<div>Digest: <textarea name="digest" rows="2" data-supported="True" data-required="True" data-previousvalue=""></textarea>
</div>

<p class="text-center">
<input type="submit" class="btn btn-primary" value="Create DS Record" />
</p>
</form>
</div>