(function(wp) {
    const { data, dispatch } = wp.data;

    let lastCheckedSaveTime = 0;
    let wasPublishing = false;
    let checkingStatus = false;

    // Função para verificar o status da limpeza do cache
    function checkCacheStatus() {
        if (checkingStatus) return;
        
        checkingStatus = true;

        fetch(hgCloudflare.restUrl, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': hgCloudflare.nonce
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                dispatch('core/notices').createNotice(
                    'success',
                    '✅ [ CLOUDFLARE ] ' + data.message,
                    {
                        isDismissible: true,
                        type: 'snackbar',
                        duration: 5000
                    }
                );
            } else if (data.status === 'error') {
                dispatch('core/notices').createNotice(
                    'error',
                    '❌ [ CLOUDFLARE ] ' + data.message,
                    {
                        isDismissible: true,
                        type: 'snackbar',
                        duration: 5000
                    }
                );
            }
            checkingStatus = false;
        })
        .catch(error => {
            console.error('Erro ao verificar status do CloudFlare:', error);
            checkingStatus = false;
        });
    }

    // Monitorar salvamento de posts
    let previousIsSaving = false;
    
    const unsubscribe = data.subscribe(() => {
        const editor = data.select('core/editor');
        
        if (!editor) return;

        const isSaving = editor.isSavingPost();
        const isAutosaving = editor.isAutosavingPost();
        const didSucceed = editor.didPostSaveRequestSucceed();
        const saveTime = editor.getEditedPostAttribute('modified');

        // Detectar quando terminou de salvar (estava salvando e agora não está mais)
        if (previousIsSaving && !isSaving && !isAutosaving && didSucceed) {
            // Verificar se já não checamos este salvamento
            if (saveTime !== lastCheckedSaveTime) {
                lastCheckedSaveTime = saveTime;
                
                // Aguardar para garantir que o backend processou
                setTimeout(() => {
                    checkCacheStatus();
                }, 1500);
            }
        }

        previousIsSaving = isSaving && !isAutosaving;
    });

    // Limpar quando sair
    window.addEventListener('beforeunload', () => {
        if (unsubscribe) unsubscribe();
    });

})(window.wp);
