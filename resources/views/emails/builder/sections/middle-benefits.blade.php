{{-- middle-benefits — 3 benefit cards (lifted from fretiq-standard-01-branded-campaign.html). BuilderStateValidator guarantees exactly 3 items. --}}
<tr>
  <td class="mobile-pad" style="padding:36px 32px 18px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        @foreach ($slots['benefits'] as $benefit)
        <td class="mobile-stack{{ $loop->first ? '' : ' mobile-gap' }}" width="33.33%" valign="top" style="width:33.33%;padding:0 8px 18px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f8fc;border-top:4px solid {{ $loop->iteration % 2 === 0 ? '#e3c400' : '#0548a5' }};">
            <tr>
              <td style="padding:22px 16px;">
                <p style="margin:0 0 8px;font-size:17px;line-height:22px;font-weight:bold;color:#002e71;">{{ $benefit['title'] }}</p>
                <p style="margin:0;font-size:14px;line-height:21px;color:#3e526b;">{{ $benefit['text'] }}</p>
              </td>
            </tr>
          </table>
        </td>
        @endforeach
      </tr>
    </table>
  </td>
</tr>
