<script>
  // Přepínač obrazovek sekce Testování podle routy (routes.js `testing*`).
  import { router } from '../../router.svelte.js';
  import DatasetsPage from './DatasetsPage.svelte';
  import RunsPage from './RunsPage.svelte';
  import RunDetailPage from './RunDetailPage.svelte';
  import MessagePage from './MessagePage.svelte';
  import ComparePage from './ComparePage.svelte';
  import MessageRunsPage from './MessageRunsPage.svelte';

  const name = $derived(router.route.name);
</script>

{#if name === 'testingDatasets'}
  <DatasetsPage />
{:else if name === 'testingRuns'}
  <RunsPage />
{:else if name === 'testingRun'}
  <RunDetailPage />
{:else if name === 'testingMessage'}
  <MessagePage />
{:else if name === 'testingCompare'}
  <ComparePage />
{:else if name === 'testingMessageRuns'}
  <MessageRunsPage />
{/if}
