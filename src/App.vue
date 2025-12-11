<template>
	<NcAppContent>
		<div id="foldercast">
			<h2>Your Podcast Feeds</h2>
			<NcLoadingIcon v-if="loading" />
			<div v-else>
				<ul v-if="feeds.length > 0">
					<li v-for="feed in feeds" :key="feed.id" class="feed-item">
						<div>
							<strong>Token:</strong> {{ feed.token }} <br/>
							<a :href="getFeedUrl(feed.token)" target="_blank">RSS Link</a>
						</div>
						<button @click="deleteFeed(feed.id)">Delete</button>
					</li>
				</ul>
				<p v-else>No feeds created yet. Go to Files app and use "Turn into Podcast" action.</p>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
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
		}
	},
	mounted() {
		this.fetchFeeds()
	},
	methods: {
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
		deleteFeed(id) {
			if (!confirm('Are you sure?')) return
			axios.delete(generateUrl('/apps/foldercast/api/feeds/' + id))
				.then(() => {
					this.fetchFeeds()
				})
				.catch((error) => {
					console.error(error)
					alert('Error deleting feed')
				})
		}
	}
}
</script>

<style scoped>
#foldercast {
	padding: 20px;
}
.feed-item {
	display: flex;
	justify-content: space-between;
	padding: 10px;
	border-bottom: 1px solid #ccc;
}
</style>
