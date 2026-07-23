{{-- middle-process — source-backed TCL 3PL journey: exactly 3 editable steps plus one highlight. --}}
<tr>
  <td class="pad-mobile" style="padding:8px 30px 24px 30px;background-color:#ffffff;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        @foreach ($slots['process_steps'] as $step)
        <td class="process-step" width="29%" align="center" valign="middle" style="width:29%;padding:16px 8px;background-color:#002e71;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.4;color:#ffffff;font-weight:bold;">
          {{ $step }}
        </td>
        @unless ($loop->last)
        <td class="process-arrow" width="6.5%" align="center" valign="middle" style="width:6.5%;padding:0 4px;font-family:Arial,Helvetica,sans-serif;font-size:24px;line-height:1;color:#e3c400;font-weight:bold;">
          &rsaquo;
        </td>
        @endunless
        @endforeach
      </tr>
    </table>
  </td>
</tr>
<tr>
  <td class="pad-mobile" style="padding:0 30px 24px 30px;background-color:#ffffff;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f7f9fc;border-left:5px solid #e3c400;">
      <tr>
        <td style="padding:16px 18px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#002e71;font-weight:bold;">
          {{ $slots['process_highlight'] }}
        </td>
      </tr>
    </table>
  </td>
</tr>
