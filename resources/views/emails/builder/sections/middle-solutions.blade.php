<tr>
  <td class="mobile-pad" style="padding:8px 24px 24px;background-color:#ffffff;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
      @foreach (array_chunk($slots['solutions'], 2) as $row)
      <tr>
        @foreach ($row as $solution)
        <td class="mobile-stack{{ $loop->first ? '' : ' mobile-gap' }}" width="50%" valign="top" style="width:50%;padding:8px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f8fc;border-top:4px solid #0548a5;">
            <tr><td style="padding:18px 16px;"><p style="margin:0 0 7px;color:#002e71;font-size:17px;font-weight:bold;">{{ $solution['title'] }}</p><p style="margin:0;color:#3e526b;font-size:14px;line-height:21px;">{{ $solution['text'] }}</p></td></tr>
          </table>
        </td>
        @endforeach
        @if (count($row) === 1)<td class="mobile-stack" width="50%" style="width:50%;"></td>@endif
      </tr>
      @endforeach
    </table>
  </td>
</tr>
