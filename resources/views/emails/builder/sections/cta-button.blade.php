{{--
    cta-button — mso v:roundrect + anchor CTA button. Color follows the hero
    variant: blue on white hero (fretiq-standard-01-prospection.html), yellow
    on navy hero (fretiq-standard-02-annonce.html's yellow CTA, repurposed here
    for the navy pairing per plan). href/label are dynamic (catalog CTA intent
    URL + user-authored label).
--}}
@php
    $ctaBg     = $heroVariant === 'navy' ? '#e3c400' : '#0548a5';
    $ctaText   = $heroVariant === 'navy' ? '#002e71' : '#ffffff';
    $ctaWidth  = $heroVariant === 'navy' ? '200px' : '220px';
@endphp
<tr>
  <td class="pad-mobile" align="center" style="padding:16px 30px 8px 30px;background-color:#ffffff;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto;">
      <tr>
        <td align="center" style="border-radius:4px;background-color:{{ $ctaBg }};">
          <!--[if mso]>
          <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $ctaUrl }}" style="height:46px;v-text-anchor:middle;width:{{ $ctaWidth }};" arcsize="10%" strokecolor="{{ $ctaBg }}" fillcolor="{{ $ctaBg }}">
          <w:anchorlock/>
          <center style="color:{{ $ctaText }};font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;">{{ $ctaLabel }}</center>
          </v:roundrect>
          <![endif]-->
          <!--[if !mso]><!-->
          <a href="{{ $ctaUrl }}" target="_blank" style="display:inline-block;padding:14px 32px;color:{{ $ctaText }};text-decoration:none;font-weight:bold;font-size:15px;font-family:Arial,Helvetica,sans-serif;border-radius:4px;background-color:{{ $ctaBg }};">{{ $ctaLabel }}</a>
          <!--<![endif]-->
        </td>
      </tr>
    </table>
  </td>
</tr>
