<template>
	<NcAppContent>
		<div id="foldercast">
			<div class="header">
				<h2>{{ t('foldercast', 'Your Podcast Feeds') }}</h2>
				<button @click="openModal('create')" class="primary">
					{{ t('foldercast', 'Create New Podcast') }}
				</button>
			</div>

			<!-- Create Modal -->
			<div v-if="activeModal === 'create'" class="modal-overlay">
				<div class="modal">
					<h3>{{ t('foldercast', 'Create Podcast') }}</h3>
					<label>{{ t('foldercast', 'Podcast Name (will create a folder)') }}</label>
					<input v-model="newPodcastName" type="text" :placeholder="t('foldercast', 'My Awesome Podcast')" />
					<div class="actions">
						<button @click="createPodcast" :disabled="processing" class="primary">
							{{ t('foldercast', 'Create') }}
						</button>
						<button @click="closeModal">{{ t('foldercast', 'Cancel') }}</button>
					</div>
				</div>
			</div>

			<!-- Delete Confirmation Modal -->
			<div v-if="activeModal === 'delete'" class="modal-overlay">
				<div class="modal">
					<h3>{{ t('foldercast', 'Delete Podcast Feed?') }}</h3>
					<p>{{ t('foldercast', 'Are you sure you want to delete this feed?') }}</p>
					<p><em>{{ t('foldercast', 'The files on your disk will NOT be deleted.') }}</em></p>
					<div class="actions">
						<button @click="confirmDelete" :disabled="processing" class="danger">
							{{ t('foldercast', 'Delete Feed') }}
						</button>
						<button @click="closeModal">{{ t('foldercast', 'Cancel') }}</button>
					</div>
				</div>
			</div>

			<!-- Edit Modal -->
			<div v-if="activeModal === 'edit'" class="modal-overlay">
				<div class="modal">
					<h3>{{ t('foldercast', 'Edit Podcast Metadata') }}</h3>

					<label>{{ t('foldercast', 'Title Override') }}</label>
					<input v-model="editForm.title" type="text" :placeholder="t('foldercast', 'Leave empty to use folder name')" />

					<label>{{ t('foldercast', 'Description') }}</label>
					<textarea v-model="editForm.description" :placeholder="t('foldercast', 'Podcast description...')"></textarea>

					<label>{{ t('foldercast', 'Author') }}</label>
					<input v-model="editForm.author" type="text" :placeholder="t('foldercast', 'Author Name')" />

					<label>{{ t('foldercast', 'Podcast Logo') }}</label>
					<div class="logo-upload">
						<img v-if="logoPreview || editForm.imageUrl" :src="logoPreview || editForm.imageUrl" class="logo-preview" alt="Podcast Logo" />
						<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" @change="onLogoSelected" />
						<small>{{ t('foldercast', 'Or enter an image URL manually:') }}</small>
						<input v-model="editForm.imageUrl" type="url" placeholder="https://example.com/image.jpg" />
					</div>

					<div class="form-group checkbox-group">
						<label>
							<input type="checkbox" v-model="editForm.flatten" />
							{{ t('foldercast', 'Include subfolders') }}
						</label>
					</div>

					<label>{{ t('foldercast', 'Episode order') }}</label>
					<select v-model="editForm.sortBy">
						<option value="date_desc">{{ t('foldercast', 'Newest first (by date)') }}</option>
						<option value="date_asc">{{ t('foldercast', 'Oldest first (by date)') }}</option>
						<option value="name">{{ t('foldercast', 'Alphabetical (by title/name)') }}</option>
					</select>

					<label>{{ t('foldercast', 'Auto-remove after (days)') }}</label>
					<input v-model.number="editForm.autoremoveDays" type="number" min="0" max="3650" placeholder="0 = disabled" />
					<small class="warning-text">{{ t('foldercast', 'Episodes older than this many days will be permanently deleted from disk (via trashbin). 0 disables auto-remove.') }}</small>

					<div class="actions">
						<button @click="saveEdit" :disabled="processing" class="primary">
							{{ t('foldercast', 'Save Changes') }}
						</button>
						<button @click="closeModal">{{ t('foldercast', 'Cancel') }}</button>
					</div>
				</div>
			</div>

			<NcLoadingIcon v-if="loading" />
			<div v-else>
				<ul v-if="feeds.length > 0">
					<li v-for="feed in feeds" :key="feed.id" class="feed-item">
						<div class="feed-info">
							<strong v-if="feed.path && feed.path !== 'Unknown/Deleted'">/{{ feed.path }}</strong>
							<strong v-else class="text-deleted">{{ t('foldercast', 'Folder deleted or missing') }}</strong>
							<div class="feed-meta" v-if="feed.config && feed.config.title">
								<small>{{ t('foldercast', 'Title: {title}', { title: feed.config.title }) }}</small>
							</div>
							<code :title="feed.token">{{ feed.token.substring(0, 8) }}...</code>
							<div class="feed-links">
								<a :href="getFeedUrl(feed.token)" target="_blank" rel="noopener noreferrer">🔗 {{ t('foldercast', 'Open RSS Feed') }}</a>
								<button @click="copyFeedUrl(feed.token)" class="copy-btn">
									📋 {{ t('foldercast', 'Copy RSS URL') }}
								</button>
							</div>
						</div>
						<div class="feed-actions">
							<button @click="openEdit(feed)" class="secondary">
								{{ t('foldercast', 'Edit') }}
							</button>
							<button @click="openDelete(feed)" class="danger">
								{{ t('foldercast', 'Delete') }}
							</button>
						</div>
					</li>
				</ul>
				<p v-else>{{ t('foldercast', 'No feeds created yet.') }}</p>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'
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
			activeModal: null,
			newPodcastName: '',
			selectedFeed: null,
			editForm: {
				title: '',
				description: '',
				author: '',
				imageUrl: '',
				autoremoveDays: 0,
				flatten: true,
				sortBy: 'date_desc',
			},
			initialAutoremoveDays: 0,
			logoFile: null,
			logoPreview: null,
			processing: false,
		}
	},
	mounted() {
		this.fetchFeeds()
	},
	methods: {
		openModal(name) {
			this.activeModal = name
		},
		closeModal() {
			if (this.logoPreview && this.logoPreview.startsWith('blob:')) {
				URL.revokeObjectURL(this.logoPreview)
			}
			this.activeModal = null
			this.selectedFeed = null
			this.newPodcastName = ''
			this.editForm = {
				title: '',
				description: '',
				author: '',
				imageUrl: '',
				autoremoveDays: 0,
				flatten: true,
				sortBy: 'date_desc',
			}
			this.initialAutoremoveDays = 0
			this.logoFile = null
			this.logoPreview = null
		},
		openDelete(feed) {
			this.selectedFeed = feed
			this.openModal('delete')
		},
		openEdit(feed) {
			this.selectedFeed = feed
			const config = feed.config || {}
			this.editForm = {
				title: config.title || '',
				description: config.description || '',
				author: config.author || '',
				imageUrl: config.imageUrl || '',
				autoremoveDays: config.autoremoveDays || 0,
				flatten: config.flatten ?? true,
				sortBy: config.sortBy || 'date_desc',
			}
			this.initialAutoremoveDays = config.autoremoveDays || 0
			this.logoFile = null
			this.logoPreview = null
			if (config.logoFileId) {
				this.logoPreview = this.getFeedUrl(feed.token) + '/logo?v=' + config.logoFileId
			}
			this.openModal('edit')
		},
		onLogoSelected(event) {
			const file = event.target.files[0]
			if (!file) return
			if (this.logoPreview && this.logoPreview.startsWith('blob:')) {
				URL.revokeObjectURL(this.logoPreview)
			}
			this.logoFile = file
			this.logoPreview = URL.createObjectURL(file)
		},
		fetchFeeds() {
			this.loading = true
			axios.get(generateUrl('/apps/foldercast/api/feeds'))
				.then((response) => {
					this.feeds = (response.data || []).map((feed) => {
						let config = {}
						try {
							config = feed.configuration ? JSON.parse(feed.configuration) : {}
						} catch (e) {
							config = {}
						}
						return {
							...feed,
							config,
						}
					})
					this.loading = false
				})
				.catch((error) => {
					const msg = error.response?.data?.error || error.message || this.t('foldercast', 'Error loading feeds')
					showError(msg)
					this.loading = false
				})
		},
		getFeedUrl(token) {
			return window.location.origin + generateUrl('/apps/foldercast/feed/' + token)
		},
		async copyFeedUrl(token) {
			const url = this.getFeedUrl(token)
			try {
				if (navigator.clipboard?.writeText) {
					await navigator.clipboard.writeText(url)
				} else {
					const input = document.createElement('input')
					input.value = url
					document.body.appendChild(input)
					input.select()
					document.execCommand('copy')
					document.body.removeChild(input)
				}
				showSuccess(this.t('foldercast', 'RSS feed URL copied to clipboard!'))
			} catch (e) {
				showError(this.t('foldercast', 'Could not copy RSS feed URL'))
			}
		},
		async createPodcast() {
			if (!this.newPodcastName.trim()) return
			this.processing = true

			try {
				const response = await axios.post(generateUrl('/apps/foldercast/api/feeds'), {
					podcastName: this.newPodcastName.trim(),
				})
				if (response.data && response.data.existing) {
					showSuccess(this.t('foldercast', 'Existing podcast feed retrieved!'))
				} else {
					showSuccess(this.t('foldercast', 'Podcast created successfully!'))
				}
				this.closeModal()
				this.fetchFeeds()
			} catch (error) {
				const msg = error.response?.data?.error || error.message || this.t('foldercast', 'Error creating podcast')
				showError(msg)
			} finally {
				this.processing = false
			}
		},
		confirmDelete() {
			if (!this.selectedFeed) return
			this.processing = true

			axios.delete(generateUrl('/apps/foldercast/api/feeds/' + this.selectedFeed.id))
				.then(() => {
					showSuccess(this.t('foldercast', 'Feed deleted'))
					this.closeModal()
					this.fetchFeeds()
				})
				.catch((error) => {
					const msg = error.response?.data?.error || error.message || this.t('foldercast', 'Error deleting feed')
					showError(msg)
				})
				.finally(() => {
					this.processing = false
				})
		},
		async saveEdit() {
			if (!this.selectedFeed) return

			if (this.initialAutoremoveDays === 0 && this.editForm.autoremoveDays > 0) {
				const confirmed = window.confirm(
					this.t(
						'foldercast',
						'Warning: Enabling auto-remove will permanently delete audio episodes older than {days} days from disk. Are you sure you want to proceed?',
						{ days: this.editForm.autoremoveDays }
					)
				)
				if (!confirmed) {
					return
				}
			}

			this.processing = true

			try {
				// 1. Upload Logo if selected
				if (this.logoFile) {
					const formData = new FormData()
					formData.append('logo', this.logoFile)
					await axios.post(
						generateUrl('/apps/foldercast/api/feeds/' + this.selectedFeed.id + '/logo'),
						formData,
						{ headers: { 'Content-Type': 'multipart/form-data' } }
					)
				}

				// 2. Update Config
				await axios.put(generateUrl('/apps/foldercast/api/feeds/' + this.selectedFeed.id), {
					configuration: this.editForm,
				})

				showSuccess(this.t('foldercast', 'Feed updated'))
				this.closeModal()
				this.fetchFeeds()
			} catch (error) {
				const msg = error.response?.data?.error || error.message || this.t('foldercast', 'Error updating feed')
				showError(msg)
			} finally {
				this.processing = false
			}
		},
	},
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
	border: 1px solid var(--color-border, #ddd);
	border-radius: 8px;
	margin-bottom: 10px;
	background: var(--color-main-background);
}
.text-deleted {
	color: var(--color-text-maxcontrast, #888);
	font-style: italic;
}
.feed-links {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 6px;
}
.copy-btn {
	padding: 4px 8px;
	font-size: 0.9em;
}
.feed-actions {
	display: flex;
	gap: 10px;
}
.modal-overlay {
	position: fixed;
	top: 0; left: 0; right: 0; bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	justify-content: center;
	align-items: center;
	z-index: 1000;
}
.modal {
	background: var(--color-main-background);
	padding: 20px;
	border-radius: 8px;
	min-width: 420px;
	max-width: 90vw;
	max-height: 90vh;
	overflow-y: auto;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}
.modal input[type="text"],
.modal input[type="url"],
.modal input[type="number"],
.modal textarea,
.modal select {
	width: 100%;
	margin: 8px 0;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	box-sizing: border-box;
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.modal textarea {
	min-height: 80px;
}
.checkbox-group {
	margin: 10px 0;
}
.checkbox-group label {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
}
.warning-text {
	color: var(--color-text-maxcontrast, #666);
	display: block;
	margin-top: -4px;
	margin-bottom: 8px;
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
	color: var(--color-main-text);
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
.logo-preview {
	max-width: 100px;
	max-height: 100px;
	display: block;
	margin-bottom: 5px;
	border-radius: 4px;
	border: 1px solid var(--color-border, #ddd);
}
.logo-upload {
	margin-bottom: 10px;
	padding: 10px;
	border: 1px dashed var(--color-border);
	border-radius: 4px;
}
</style>
