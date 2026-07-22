{{-- middle-kpi — 3-stat KPI strip (lifted from fretiq-standard-02-annonce.html). BuilderStateValidator guarantees exactly 3 items. --}}
<tr>
  <td class="pad-mobile" style="padding:8px 30px 24px 30px;background-color:#ffffff;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#002e71;">
      <tr>
        @foreach ($slots['kpis'] as $kpi)
        <td class="kpi-col" width="{{ $loop->last ? '34%' : '33%' }}" align="center" valign="top" style="padding:20px 10px;">
          <div style="font-family:Arial,Helvetica,sans-serif;font-size:28px;line-height:1.2;color:#f5b51d;font-weight:bold;">{{ $kpi['value'] }}</div>
          <div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.4;color:#ffffff;text-transform:uppercase;letter-spacing:0.5px;padding-top:6px;">{{ $kpi['label'] }}</div>
        </td>
        @endforeach
      </tr>
    </table>
  </td>
</tr>
