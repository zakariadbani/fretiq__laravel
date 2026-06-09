<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Désinscription confirmée</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f5f8fa;
            color: #374151;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            max-width: 480px;
            width: 100%;
            padding: 48px 40px;
            text-align: center;
        }

        .icon {
            width: 56px;
            height: 56px;
            background-color: #d1fae5;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 28px;
        }

        h1 {
            font-size: 22px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 12px;
        }

        p {
            font-size: 15px;
            color: #6b7280;
            line-height: 1.6;
        }

        .note {
            margin-top: 24px;
            font-size: 13px;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon" aria-hidden="true">&#10003;</div>

        <h1>Vous êtes désinscrit</h1>

        <p>
            Votre adresse e-mail a été retirée de notre liste de contacts.
            Vous ne recevrez plus de messages de notre part.
        </p>

        <p class="note">
            Si vous pensez qu'il s'agit d'une erreur, vous pouvez répondre
            directement à l'un de nos e-mails pour nous en informer.
        </p>
    </div>
</body>
</html>
