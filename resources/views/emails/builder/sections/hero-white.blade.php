{{-- hero-white — white background hero (lifted from fretiq-standard-01-prospection.html / -02-annonce.html). Pairs with header-logo-center. --}}
<tr>
  <td class="pad-mobile" style="padding:32px 30px 8px 30px;background-color:#ffffff;">
    <h1 style="margin:0 0 20px 0;font-family:Arial,Helvetica,sans-serif;font-size:22px;line-height:1.3;color:#002e71;font-weight:bold;">{{ $slots['hero_title'] }}</h1>
    <p class="body-text" style="margin:0 0 16px 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;color:#333333;">{{ $copy['greeting'] }}@if($includeFirstName) @{{contact.first_name}}@endif,</p>
    @foreach ($slots['intro'] as $paragraph)
    <p class="body-text" style="margin:0 0 16px 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;color:#333333;">{{ $paragraph }}</p>
    @endforeach
  </td>
</tr>
