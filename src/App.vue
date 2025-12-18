<template>
	<NcAppContent>
		<div id="foldercast">
			<div class="header">
				<h2>Your Podcast Feeds</h2>
				<button @click="openModal('create')" class="primary">Create New Podcast</button>
			</div>
			
            <!-- Create Modal -->
			<div v-if="activeModal === 'create'" class="modal-overlay">
				<div class="modal">
					<h3>Create Podcast</h3>
					<label>Podcast Name (will create a folder)</label>
					<input v-model="newPodcastName" type="text" placeholder="My Awesome Podcast" />
					<div class="actions">
						<button @click="createPodcast" :disabled="processing" class="primary">Create</button>
						<button @click="closeModal">Cancel</button>
					</div>
				</div>
			</div>

            <!-- Delete Confirmation Modal -->
			<div v-if="activeModal === 'delete'" class="modal-overlay">
				<div class="modal">
					<h3>Delete Podcast Feed?</h3>
                    <p>Are you sure you want to delete this feed?</p>
                    <p><em>The files on your disk will NOT be deleted.</em></p>
					<div class="actions">
						<button @click="confirmDelete" :disabled="processing" class="danger">Delete Feed</button>
						<button @click="closeModal">Cancel</button>
					</div>
				</div>
			</div>

            <!-- Edit Modal -->
			<div v-if="activeModal === 'edit'" class="modal-overlay">
				<div class="modal">
					<h3>Edit Podcast Metadata</h3>
                    
                    <label>Title Override</label>
					<input v-model="editForm.title" type="text" placeholder="Leave empty to use folder name" />
                    
                    <label>Description</label>
                    <textarea v-model="editForm.description" placeholder="Podcast description..."></textarea>
                    
                    <label>Author</label>
					<input v-model="editForm.author" type="text" placeholder="Author Name" />

                    <label>Image URL (Thumbnail)</label>
                    <input v-model="editForm.imageUrl" type="text" placeholder="https://example.com/image.jpg" />

					<div class="actions">
						<button @click="saveEdit" :disabled="processing" class="primary">Save Changes</button>
						<button @click="closeModal">Cancel</button>
					</div>
				</div>
			</div>

			<NcLoadingIcon v-if="loading" />
			<div v-else>
				<ul v-if="feeds.length > 0">
					<li v-for="feed in feeds" :key="feed.id" class="feed-item">
						<div class="feed-info">
							<strong>/{{ feed.path || 'Unknown' }}</strong>
                            <div class="feed-meta" v-if="feed.configuration">
                                <small v-if="JSON.parse(feed.configuration).title">Title: {{ JSON.parse(feed.configuration).title }}</small>
                            </div>
							<code :title="feed.token">{{ feed.token.substring(0,8) }}...</code> <br/>
							<a :href="getFeedUrl(feed.token)" target="_blank">🔗 RSS Feed</a>
						</div>
                        <div class="feed-actions">
						    <button @click="openEdit(feed)" class="secondary">Edit</button>
						    <button @click="openDelete(feed)" class="danger">Delete</button>
                        </div>
					</li>
				</ul>
				<p v-else>No feeds created yet.</p>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { getRequestToken } from '@nextcloud/auth'
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

export default {
	name: 'App',
	components: {
		NcAppContent,
		NcLoadingIcon,
	},
	data() {
		return {
			feeds: [],
			loading: true,
            activeModal: null, // 'create', 'delete', 'edit'
			newPodcastName: '',
            selectedFeed: null,
            editForm: {
                title: '',
                description: '',
                author: '',
                imageUrl: ''
            },
			processing: false
		}
	},
	mounted() {
		this.fetchFeeds()
	},
	methods: {
        openModal(name) {
            this.activeModal = name;
        },
        closeModal() {
            this.activeModal = null;
            this.selectedFeed = null;
            this.newPodcastName = '';
            this.editForm = { title: '', description: '', author: '', imageUrl: '' };
        },
        openDelete(feed) {
            this.selectedFeed = feed;
            this.openModal('delete');
        },
        openEdit(feed) {
            this.selectedFeed = feed;
            const config = feed.configuration ? JSON.parse(feed.configuration) : {};
            this.editForm = {
                title: config.title || '',
                description: config.description || '',
                author: config.author || '',
                imageUrl: config.imageUrl || ''
            };
            this.openModal('edit');
        },
		fetchFeeds() {
			this.loading = true
			axios.get(generateUrl('/apps/foldercast/api/feeds'))
				.then((response) => {
					this.feeds = response.data
					this.loading = false
				})
				.catch((error) => {
					console.error(error)
					this.loading = false
				})
		},
		getFeedUrl(token) {
			return generateUrl('/apps/foldercast/feed/' + token)
		},
		async createPodcast() {
			if (!this.newPodcastName) return
			this.processing = true
			
			try {
                console.log('FolderCast: Starting podcast creation for', this.newPodcastName);
                // We now let the backend handle the folder creation to avoid WebDAV 401 issues on some environments
				await axios.post(generateUrl('/apps/foldercast/api/feeds'), {
                    podcastName: this.newPodcastName
				})
				
                OC.Notification.showTemporary('Podcast created successfully!');
				this.closeModal()
				this.fetchFeeds()
				
			} catch (error) {
				console.error('FolderCast Creation Error:', error)
				alert('Error creating podcast: ' + (error.response?.data?.error || error.message))
			} finally {
				this.processing = false
			}
		},
		confirmDelete() {
            if (!this.selectedFeed) return;
            this.processing = true;
            console.log('FolderCast: Deleting feed', this.selectedFeed.id);
            
			axios.delete(generateUrl('/apps/foldercast/api/feeds/' + this.selectedFeed.id))
				.then(() => {
                    OC.Notification.showTemporary('Feed deleted');
                    this.closeModal();
					this.fetchFeeds();
				})
				.catch((error) => {
					console.error(error)
					alert('Error deleting feed')
				})
                .finally(() => {
                    this.processing = false;
                })
		},
        saveEdit() {
            if (!this.selectedFeed) return;
            this.processing = true;
            
            axios.put(generateUrl('/apps/foldercast/api/feeds/' + this.selectedFeed.id), {
                configuration: this.editForm
            })
            .then(() => {
                OC.Notification.showTemporary('Feed updated');
                this.closeModal();
                this.fetchFeeds();
            })
            .catch((error) => {
                console.error(error);
                alert('Error updating feed');
            })
            .finally(() => {
                this.processing = false;
            });
        }
	}
}
</script>

<style scoped>
#foldercast {
	padding: 20px;
}
.header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}
.feed-item {
	display: flex;
	justify-content: space-between;
    align-items: center;
	padding: 15px;
	border: 1px solid #ddd;
	border-radius: 8px;
	margin-bottom: 10px;
	background: var(--color-main-background);
}
.feed-actions {
    display: flex;
    gap: 10px;
}
.modal-overlay {
	position: fixed;
	top: 0; left: 0; right: 0; bottom: 0;
	background: rgba(0,0,0,0.5);
	display: flex;
	justify-content: center;
	align-items: center;
	z-index: 1000;
}
.modal {
	background: var(--color-main-background);
	padding: 20px;
	border-radius: 8px;
	min-width: 400px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.modal input, .modal textarea {
	width: 100%;
	margin: 10px 0;
	padding: 8px;
    border: 1px solid var(--color-border);
    border-radius: 4px;
}
.modal textarea {
    min-height: 80px;
}
.actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
    margin-top: 15px;
}
button {
	padding: 8px 16px;
	cursor: pointer;
    border-radius: 4px;
    border: 1px solid var(--color-border);
    background-color: var(--color-main-background);
}
.primary {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border: none;
}
.secondary {
    /* Uses default button style */
}
.danger {
	color: var(--color-error);
	border: 1px solid var(--color-error);
	background: transparent;
}
.danger:hover {
    background-color: var(--color-error);
    color: white;
}
</style>
