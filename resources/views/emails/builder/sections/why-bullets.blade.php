{{-- why-bullets — "Pourquoi TCL Transport ?" bullet list (lifted from fretiq-standard-01-prospection.html). Fixed title, dynamic bullet items (2-4). --}}
<tr>
  <td class="pad-mobile" style="padding:0 30px 8px 30px;background-color:#ffffff;">
    <h2 style="margin:0 0 14px 0;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.4;color:#002e71;font-weight:bold;">Pourquoi TCL Transport ?</h2>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
      @foreach ($slots['bullets'] as $bullet)
      <tr>
        <td width="24" valign="top" style="padding:6px 10px 6px 0;">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="10" height="10"><tr><td width="10" height="10" style="background-color:#e3c400;font-size:0;line-height:0;">&nbsp;</td></tr></table>
        </td>
        <td valign="top" style="padding:6px 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;color:#333333;">{{ $bullet }}</td>
      </tr>
      @endforeach
    </table>
  </td>
</tr>
