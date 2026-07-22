{{-- closing-signature — user-editable closing ask (optional `closing_line` slot, falls back to
     SectionCatalog::DEFAULT_CLOSING_LINE) + fixed signature block (structure lifted from
     fretiq-standard-01-prospection.html). Only the closing ask is a slot — the signature stays fixed. --}}
<tr>
  <td class="pad-mobile" style="padding:16px 30px 32px 30px;background-color:#ffffff;">
    <p class="body-text" style="margin:0 0 20px 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;color:#333333;">{{ $slots['closing_line'] ?? \App\Services\Campaign\TemplateBuilder\SectionCatalog::DEFAULT_CLOSING_LINE }}</p>
    <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;color:#002e71;font-weight:bold;">Cordialement,<br>L'équipe TCL Transport</p>
  </td>
</tr>
