{{--
    footer-compact — minimal address + compliance footer (lifted from
    fretiq-standard-01-branded-campaign.html) MINUS the désabonner anchor
    (Zoho Campaigns owns unsubscribe for builder-authored templates — see
    plan). Compliance sentence is kept.
--}}
<tr>
  <td class="mobile-pad" style="padding:28px 40px;background-color:#002e71;text-align:center;">
    <p style="margin:0 0 9px;font-size:12px;line-height:19px;color:#dce9f8;">{{ $copy['compact_address'] }}</p>
    <p style="margin:0;font-size:12px;line-height:19px;color:#dce9f8;">{{ $copy['compact_compliance'] }}</p>
  </td>
</tr>
