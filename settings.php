<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap">
    <div id="icon-themes" class="icon32"></div>
    <h2>CloudFlare - Configurações</h2>

    <?php settings_errors('hgcloudflare-settings') ?>

    <?php if ( $decrypt_failed ): ?>
        <div class="notice notice-error">
            <p>Não foi possível ler os tokens salvos (a chave de criptografia ou os salts do WordPress foram alterados). Cadastre os tokens novamente.</p>
        </div>
    <?php endif; ?>

    <?php if ( ! $has_dedicated_key ): ?>
        <div class="notice notice-warning">
            <p>
                Recomendado: defina <code>HG_CLOUDFLARE_ENCRYPTION_KEY</code> no <code>wp-config.php</code> para que os tokens não dependam dos salts do WordPress.
                Ou defina os tokens diretamente com <code>HG_CLOUDFLARE_ZONE_ID_1</code> / <code>HG_CLOUDFLARE_API_TOKEN_1</code>.
            </p>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?php echo esc_url( admin_url( 'options-general.php?page=hgcloudflare-settings' ) ) ?>" autocomplete="off">
        <input type="hidden" name="hgcloudflare_settings" value="1" />
        <?php wp_nonce_field( HG_Cloudflare::SAVE_ACTION ) ?>

        <table class="form-table" role="presentation">
            <?php foreach ( $domains as $i => $domain ): ?>
                <tbody>
                    <tr>
                        <th>
                            <h2>Domínio <?php echo (int) $i ?></h2>
                        </th>
                    </tr>
                    <?php if ( $domain['locked'] ): ?>
                        <tr>
                            <td colspan="2"><p class="description">Definido via constantes no <code>wp-config.php</code>.</p></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row">
                            <label for="zone_id_<?php echo (int) $i ?>">
                                ID da Zona:
                            </label>
                        </th>
                        <td>
                            <input name="zone_id_<?php echo (int) $i ?>" type="text" id="zone_id_<?php echo (int) $i ?>" value="<?php echo esc_attr( $domain['zone_id'] ) ?>" class="regular-text" maxlength="32" pattern="[A-Fa-f0-9]{32}" <?php disabled( $domain['locked'] ) ?>>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="api_token_<?php echo (int) $i ?>">
                                Token da API:
                            </label>
                        </th>
                        <td>
                            <input name="api_token_<?php echo (int) $i ?>" type="password" id="api_token_<?php echo (int) $i ?>" value="" placeholder="<?php echo esc_attr( $domain['token_hint'] ) ?>" class="regular-text" autocomplete="new-password" spellcheck="false" <?php disabled( $domain['locked'] ) ?>>
                            <?php if ( ! $domain['locked'] && $domain['token_hint'] !== '' ): ?>
                                <p class="description">Deixe em branco para manter o token atual. Para remover o domínio, apague o ID da Zona.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            <?php endforeach; ?>
        </table>

        <?php submit_button(); ?>
    </form>
</div>

<style>
.form-table th {
    padding:7px 0 0 0;
}
.form-table td {
    padding:0;
}
</style>
