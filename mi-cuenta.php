<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="img/aras-favicon.svg" type="image/svg+xml">
    <title>Mi cuenta | ARAS</title>
    <link rel="stylesheet" href="admin/admin.css?v=41">
</head>
<body>
    <div class="admin-shell customer-account-page">
        <header class="admin-topbar">
            <a class="admin-brand" href="./"><span aria-hidden="true">A</span> ARAS <small>MI CUENTA</small></a>
            <a class="back-link" href="./">Volver a la tienda</a>
        </header>

        <main class="customer-account-main">
            <p class="form-error customer-account-notice" data-account-notice role="alert" hidden></p>
            <section class="auth-login-layout" id="customer-auth-view" aria-labelledby="customer-auth-title">
                <aside class="auth-login-story">
                    <p class="auth-story-kicker">ARAS · TU TIENDA</p>
                    <div class="auth-story-copy">
                        <h2>Pequeños momentos, grandes historias.</h2>
                        <p>Tu cuenta y el seguimiento de tus pedidos, siempre cerca de ti.</p>
                    </div>
                    <span class="auth-story-note"><span aria-hidden="true">✦</span> Un espacio hecho para ti</span>
                </aside>

                <section class="auth-panel customer-auth-panel">
                    <p class="eyebrow">ACCESO DE CLIENTES</p>
                    <h1 id="customer-auth-title">Qué alegría tenerte de vuelta</h1>
                    <p data-auth-description>Inicia sesión para consultar el seguimiento de tus pedidos.</p>

                    <div class="customer-account-tabs" role="tablist" aria-label="Opciones de cuenta">
                        <button type="button" class="active" data-account-tab="login" role="tab" aria-selected="true">Iniciar sesión</button>
                        <button type="button" data-account-tab="register" role="tab" aria-selected="false">Crear cuenta</button>
                    </div>

                    <form id="customer-login-form" class="customer-account-form" autocomplete="on">
                        <label for="customer-login-email">Correo electrónico</label>
                        <input id="customer-login-email" type="email" name="email" autocomplete="username" maxlength="254" placeholder="tuemail@ejemplo.com" required>

                        <label for="customer-login-password">Contraseña</label>
                        <div class="password-input-control">
                            <input id="customer-login-password" type="password" name="password" autocomplete="current-password" maxlength="128" required>
                            <button type="button" class="password-visibility-toggle button-secondary" data-password-toggle aria-pressed="false">Mostrar</button>
                        </div>
                        <p class="customer-account-message" data-form-message aria-live="polite"></p>
                        <button type="submit" class="login-submit">
                            <span class="login-spinner" aria-hidden="true"></span>
                            <span data-submit-label>Iniciar sesión</span>
                        </button>
                    </form>

                    <form id="customer-register-form" class="customer-account-form" autocomplete="on" hidden>
                        <label for="customer-register-name">Nombre completo</label>
                        <input id="customer-register-name" type="text" name="name" autocomplete="name" maxlength="120" required>

                        <label for="customer-register-email">Correo electrónico</label>
                        <input id="customer-register-email" type="email" name="email" autocomplete="email" maxlength="254" placeholder="tuemail@ejemplo.com" required>

                        <label for="customer-register-password">Contraseña</label>
                        <div class="password-input-control">
                            <input id="customer-register-password" type="password" name="password" autocomplete="new-password" minlength="10" maxlength="128" aria-describedby="customer-password-help" required>
                            <button type="button" class="password-visibility-toggle button-secondary" data-password-toggle aria-pressed="false">Mostrar</button>
                        </div>
                        <p class="customer-password-help" id="customer-password-help">Usa entre 10 y 128 caracteres.</p>
                        <p class="customer-account-message" data-form-message aria-live="polite"></p>
                        <button type="submit" class="login-submit">
                            <span class="login-spinner" aria-hidden="true"></span>
                            <span data-submit-label>Crear cuenta</span>
                        </button>
                    </form>
                </section>
            </section>

            <section class="customer-account-dashboard" id="customer-profile-view" aria-labelledby="customer-profile-title" hidden>
                <div class="customer-profile-heading">
                    <div>
                        <p class="eyebrow">ACCESO DE CLIENTES</p>
                        <h1 id="customer-profile-title">Mi cuenta</h1>
                        <p>Hola, <strong data-customer-name>Cliente</strong>. Aquí puedes consultar el estado de tus pedidos.</p>
                    </div>
                    <button class="button-secondary" type="button" data-customer-logout>Cerrar sesión</button>
                </div>
                <section class="customer-orders-panel" aria-labelledby="customer-orders-title">
                    <h2 id="customer-orders-title">Seguimiento de pedidos</h2>
                    <div class="customer-order-list" data-customer-orders aria-live="polite"></div>
                </section>
            </section>
        </main>

        <footer class="admin-footer">
            <span>© 2026 ARAS · Mi cuenta</span>
            <span>Desarrollado por <a href="https://www.strokbig.com/">STROKBIG</a></span>
        </footer>
    </div>
    <script src="scripts/mi-cuenta.js?v=1" defer></script>
</body>
</html>
