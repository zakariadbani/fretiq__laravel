{{-- middle-departures — origin/frequency table (lifted from fretiq-standard-01-prospection.html). --}}
<tr>
  <td class="pad-mobile" style="padding:8px 30px 24px 30px;background-color:#ffffff;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #dddddd;background-color:#f7f9fc;">
      <tr>
        <td width="50%" style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#ffffff;font-weight:bold;background-color:#0548a5;border-bottom:1px solid #dddddd;">{{ $copy['origin'] }}</td>
        <td width="50%" style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#ffffff;font-weight:bold;background-color:#0548a5;border-bottom:1px solid #dddddd;">{{ $copy['frequency'] }}</td>
      </tr>
      @foreach ($slots['departures'] as $row)
      <tr>
        <td width="50%" style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#333333;{{ $loop->last ? '' : 'border-bottom:1px solid #dddddd;' }}">{{ $row['origin'] }}</td>
        <td width="50%" style="padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#333333;{{ $loop->last ? '' : 'border-bottom:1px solid #dddddd;' }}">{{ $row['frequency'] }}</td>
      </tr>
      @endforeach
    </table>
  </td>
</tr>
