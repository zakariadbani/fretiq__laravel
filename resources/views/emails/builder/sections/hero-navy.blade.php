{{-- hero-navy — navy background hero (lifted from fretiq-standard-01-branded-campaign.html). Pairs with header-logo-tagline. --}}
<tr>
  <td class="mobile-pad" style="padding:48px 44px 42px;background-color:#002e71;">
    <p style="margin:0 0 14px;font-size:14px;line-height:20px;color:#f5b51d;font-weight:bold;text-transform:uppercase;letter-spacing:1px;">{{ $copy['eyebrow'] }}</p>
    <h1 class="hero-title" style="margin:0 0 18px;font-size:38px;line-height:44px;color:#ffffff;font-weight:bold;">{{ $slots['hero_title'] }}</h1>
    <p style="margin:0 0 14px;font-size:17px;line-height:27px;color:#dce9f8;">{{ $copy['greeting'] }}@if($includeFirstName) @{{contact.first_name}}@endif,</p>
    @foreach ($slots['intro'] as $paragraph)
    <p style="margin:0 0 14px;font-size:17px;line-height:27px;color:#dce9f8;">{{ $paragraph }}</p>
    @endforeach
  </td>
</tr>
