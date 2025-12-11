import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

document.addEventListener('DOMContentLoaded', () => {
    if (OCA.Files && OCA.Files.fileActions) {
        OCA.Files.fileActions.registerAction({
            name: 'foldercast',
            displayName: 'Turn into Podcast',
            mime: 'dir',
            permissions: OC.PERMISSION_READ,
            iconClass: 'icon-radio', // Standard Nextcloud icon? check
            actionHandler: (fileName, context) => {
                const fileId = context.fileInfoModel.attributes.id
                axios.post(generateUrl('/apps/foldercast/api/feeds'), {
                    folderId: fileId
                }).then((response) => {
                    console.log('Feed created', response.data)
                    OC.dialogs.alert(
                        'Podcast feed created! Token: ' + response.data.token,
                        'FolderCast'
                    )
                }).catch((error) => {
                    console.error(error)
                    OC.dialogs.alert(
                        'Error creating feed: ' + (error.response?.data?.error || error.message),
                        'FolderCast'
                    )
                })
            }
        })
    }
})
