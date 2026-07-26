{{--
    emails.builder.layout — the campaign template builder's composed HTML shell.

    Table-layout, inline-styled, Outlook/Gmail-safe email markup, lifted
    verbatim (structure + inline styles) from the 3 reference templates in
    `docs/mail templates/standard/` (outside this repo). The <style> block
    below is the UNION of both reference families' media-query classes, so
    any header/hero/middle/footer combination renders correctly regardless
    of which reference family it came from:
      - .wrap / .stack-col / .kpi-col / .pad-mobile / .body-text   (prospection/annonce family)
      - .email-shell / .mobile-pad / .mobile-stack / .mobile-gap /
        .hero-title / .cta-cell / .cta-link                        (branded-campaign family)

    Expects (all provided by TemplateComposer::compose()):
      $headerVariant, $heroVariant, $middleVariant, $footerVariant  (string variant ids)
      $slots           (validated/normalized slots array)
      $ctaLabel, $ctaUrl
      $previewText
      $logoWhiteUrl, $linkedinIconUrl

    Section resolution is by naming convention — see SectionCatalog for the
    allowed variant ids; BuilderStateValidator guarantees these are always
    one of the known ids by the time compose() reaches this view.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>TCL Transport</title>
<!--[if mso]>
<noscript>
<xml>
<o:OfficeDocumentSettings>
<o:PixelsPerInch>96</o:PixelsPerInch>
</o:OfficeDocumentSettings>
</xml>
</noscript>
<style>table,td,p,a{font-family:Arial,sans-serif!important;}</style>
<![endif]-->
<style type="text/css">
  body{margin:0;padding:0;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
  table{border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0}
  img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;display:block}
  a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important}
  @media only screen and (max-width:600px){
    .wrap{width:100% !important;}
    .email-shell{width:100% !important;}
    .stack-col{display:block !important;width:100% !important;box-sizing:border-box;}
    .kpi-col{display:block !important;width:100% !important;box-sizing:border-box;}
    .pad-mobile{padding-left:20px !important;padding-right:20px !important;}
    .mobile-pad{padding-left:22px !important;padding-right:22px !important;}
    .mobile-stack{display:block !important;width:100% !important;}
    .mobile-gap{padding-top:12px !important;}
    .body-text{font-size:16px !important;}
    .hero-title{font-size:30px !important;line-height:36px !important;}
    .cta-cell{width:100% !important;}
    .cta-link{display:block !important;}
    .process-step{display:block !important;width:100% !important;box-sizing:border-box;}
    .process-arrow{display:block !important;width:100% !important;padding:6px 0 !important;transform:rotate(90deg);}
  }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f0f0f0;font-family:Arial,Helvetica,sans-serif;">
<span style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">{{ $previewText }}{!! str_repeat('&#847;&zwnj;&nbsp;', 40) !!}</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f0f0f0;">
  <tr>
    <td align="center" style="padding:24px 10px;">
      <table role="presentation" class="wrap email-shell" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background-color:#ffffff;">

        @include('emails.builder.sections.header-' . str_replace('_', '-', $headerVariant))
        @include('emails.builder.sections.hero-' . $heroVariant)
        @include('emails.builder.sections.middle-' . $middleVariant)
        @include('emails.builder.sections.why-bullets')
        @include('emails.builder.sections.cta-button')
        @include('emails.builder.sections.closing-signature')
        @include('emails.builder.sections.footer-' . $footerVariant)

      </table>
    </td>
  </tr>
</table>
</body>
</html>
