<tr>
  <td class="pad-mobile" style="padding:8px 30px 24px;background-color:#ffffff;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f8fc;border-left:5px solid #e3c400;">
      <tr><td style="padding:18px 20px 8px;color:#002e71;font-size:20px;font-weight:bold;">{{ $slots['checklist_title'] }}</td></tr>
      @foreach ($slots['checklist_items'] as $item)
      <tr>
        <td style="padding:8px 20px {{ $loop->last ? '18px' : '8px' }};color:#3e526b;font-size:14px;line-height:21px;">
          <span style="color:#0548a5;font-weight:bold;">&#10003;&nbsp;</span>{{ $item }}
        </td>
      </tr>
      @endforeach
    </table>
  </td>
</tr>
