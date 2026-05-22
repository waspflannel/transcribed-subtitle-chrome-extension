<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        <style>
            :root {
                color: #17211b;
                font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                line-height: 1.5;
            }

            * {
                box-sizing: border-box;
            }

            body {
                background: #f6f8f7;
                margin: 0;
                min-height: 100vh;
            }

            main {
                margin: 0 auto;
                max-width: 560px;
                padding: 48px 20px;
            }

            .panel {
                background: #fff;
                border: 1px solid #d9e2de;
                border-radius: 8px;
                display: grid;
                gap: 18px;
                padding: 24px;
            }

            h1,
            p {
                margin: 0;
            }

            h1 {
                font-size: 1.35rem;
                line-height: 1.2;
            }

            p,
            li {
                color: #58635d;
            }

            form {
                display: grid;
                gap: 14px;
            }

            label {
                color: #33413a;
                display: grid;
                font-size: 0.92rem;
                font-weight: 700;
                gap: 6px;
            }

            input {
                border: 1px solid #c9d4cf;
                border-radius: 6px;
                color: #17211b;
                font: inherit;
                min-height: 42px;
                padding: 8px 10px;
                width: 100%;
            }

            button,
            .button-link {
                align-items: center;
                border-radius: 6px;
                display: inline-flex;
                font: inherit;
                font-weight: 750;
                justify-content: center;
                min-height: 40px;
                padding: 0 14px;
                text-decoration: none;
            }

            button {
                background: #14532d;
                border: 1px solid #14532d;
                color: #fff;
                cursor: pointer;
            }

            .button-link {
                border: 1px solid #c9d4cf;
                color: #33413a;
            }

            .actions {
                align-items: center;
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                justify-content: space-between;
            }

            .error-list {
                background: #fee2e2;
                border-radius: 6px;
                color: #991b1b;
                margin: 0;
                padding: 12px 14px 12px 30px;
            }

            .status {
                background: #dcfce7;
                border-radius: 6px;
                color: #166534;
                padding: 10px 12px;
            }
        </style>
    </head>
    <body>
        <main>
            <section class="panel">
                @yield('content')
            </section>
        </main>
    </body>
</html>
