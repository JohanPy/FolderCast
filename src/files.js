import { FileAction, registerFileAction, FileType } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

registerFileAction(new FileAction({
    id: 'foldercast',
    displayName: () => t('foldercast', 'Turn into Podcast'),
    iconSvgInline: () => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><circle cx="12" cy="12" r="2"/><path d="M12 4c-4.42 0-8 3.58-8 8a7.96 7.96 0 0 0 2.34 5.66l1.41-1.41A5.98 5.98 0 0 1 6 12c0-3.31 2.69-6 6-6s6 2.69 6 6c0 1.66-.67 3.16-1.76 4.24l1.41 1.41A7.96 7.96 0 0 0 20 12c0-4.42-3.58-8-8-8zm0-4C5.37 0 0 5.37 0 12c0 3.31 1.34 6.31 3.51 8.49l1.42-1.42C3.16 17.3 2 14.79 2 12 2 6.48 6.48 2 12 2s10 4.48 10 10c0 2.79-1.16 5.3-2.93 7.07l1.42 1.42C22.66 18.31 24 15.31 24 12c0-6.63-5.37-12-12-12z"/></svg>',
    enabled: (nodes) => {
        const list = Array.isArray(nodes) ? nodes : (nodes ? [nodes] : [])
        if (list.length !== 1 || !list[0]) {
            return false
        }
        const item = list[0]
        return item.type === FileType.Folder || item.isDir === true || item.attributes?.type === 'dir'
    },
    exec: async (node) => {
        try {
            const target = Array.isArray(node) ? node[0] : node
            if (!target) {
                return false
            }
            const folderId = target.fileid || target.id || target.attributes?.fileid || target.attributes?.id
            if (!folderId) {
                showError(t('foldercast', 'Could not determine folder ID'))
                return false
            }
            const response = await axios.post(generateUrl('/apps/foldercast/api/feeds'), {
                folderId,
            })
            const feed = response.data
            const feedUrl = window.location.origin + generateUrl('/apps/foldercast/feed/' + feed.token)

            let copied = false
            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(feedUrl)
                    copied = true
                } else {
                    const input = document.createElement('input')
                    input.value = feedUrl
                    document.body.appendChild(input)
                    input.select()
                    copied = document.execCommand('copy')
                    document.body.removeChild(input)
                }
            } catch (clipErr) {
                copied = false
            }

            if (copied) {
                showSuccess(t('foldercast', 'Podcast RSS feed copied to clipboard: {url}', { url: feedUrl }))
            } else {
                window.prompt(t('foldercast', 'Podcast RSS feed URL:'), feedUrl)
            }
            return true
        } catch (error) {
            const msg = error.response?.data?.error || error.message || t('foldercast', 'Error creating podcast feed')
            showError(msg)
            return false
        }
    },
}))
