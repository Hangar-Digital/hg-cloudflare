(function(wp) {
    // Verificar se o WordPress e suas dependências estão disponíveis
    if (!wp || !wp.data || !wp.element) {
        console.warn('HG CloudFlare: WordPress dependencies not available');
        return;
    }

    const { data, element } = wp;
    
    // Verificar se dispatch está disponível
    if (!data.dispatch || !data.select || !data.subscribe) {
        console.warn('HG CloudFlare: wp.data methods not available');
        return;
    }

    const { dispatch } = data;
    const { useEffect } = element;

    let lastSaveTime = 0;
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
    const unsubscribe = data.subscribe(() => {
        const editor = data.select('core/editor');
        
        if (!editor) return;

        const isSavingPost = editor.isSavingPost();
        const isAutosavingPost = editor.isAutosavingPost();
        const currentTime = Date.now();

        // Se acabou de salvar (não auto-save) e faz mais de 2 segundos desde o último salvamento
        if (!isSavingPost && !isAutosavingPost && 
            editor.didPostSaveRequestSucceed() && 
            currentTime - lastSaveTime > 2000) {
            
            lastSaveTime = currentTime;
            
            // Aguardar um pouco para garantir que o backend processou
            setTimeout(() => {
                checkCacheStatus();
            }, 1000);
        }
    });

    // Limpar quando sair
    window.addEventListener('beforeunload', () => {
        if (unsubscribe) unsubscribe();
    });

})(window.wp);
