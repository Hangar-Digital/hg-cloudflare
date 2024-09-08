<div class="wrap">
    <div id="icon-themes" class="icon32"></div>  
    <h2>CloudFlare - Configurações</h2>

    <?php settings_errors('hgcloudflare-settings') ?>

    <form method="POST" action="<?php echo get_site_url() ?>/wp-admin/options-general.php?page=hgcloudflare-settings">
        <input type="hidden" name="hgcloudflare_settings" value="1" />

        <table class="form-table" role="presentation">
            <?php for ($i = 1; $i <= 2; $i++): ?>
                <tbody>
                    <tr>
                        <th>
                            <h2>Site <?php echo $i ?></h2>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="zone_id_<?php echo $i ?>">
                                ID da Zona:
                            </label>
                        </th>
                        <td>
                            <input name="zone_id_<?php echo $i ?>" type="text" id="zone_id_<?php echo $i ?>" value="<?php echo $zone_id[$i] ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="api_token_<?php echo $i ?>">
                                Token da API:
                            </label>
                        </th>
                        <td>
                            <input name="api_token_<?php echo $i ?>" type="text" id="api_token_<?php echo $i ?>" value="<?php echo $api_token[$i] ?>" class="regular-text">
                        </td>
                    </tr>
                </tbody>
            <?php endfor; ?>
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