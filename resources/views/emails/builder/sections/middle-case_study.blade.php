<tr>
  <td class="pad-mobile" style="padding:8px 30px 24px;background-color:#ffffff;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #dce5ef;">
      <tr><td colspan="2" style="padding:18px;background-color:#002e71;color:#ffffff;font-size:20px;font-weight:bold;">{{ $slots['case_study']['title'] }}</td></tr>
      @foreach (['challenge' => 'Contrainte', 'solution' => 'Réponse TCL', 'result' => 'Résultat'] as $key => $label)
      <tr>
        <td width="28%" valign="top" style="padding:14px 16px;background-color:#f4f8fc;color:#002e71;font-size:13px;font-weight:bold;">{{ $label }}</td>
        <td width="72%" valign="top" style="padding:14px 16px;color:#3e526b;font-size:14px;line-height:21px;">{{ $slots['case_study'][$key] }}</td>
      </tr>
      @endforeach
    </table>
  </td>
</tr>
