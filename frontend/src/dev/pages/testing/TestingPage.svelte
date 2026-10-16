<script>
  // Přepínač obrazovek sekce Testování podle routy (routes.js `testing*`).
  import { router } from '../../router.svelte.js';
  import DatasetsPage from './DatasetsPage.svelte';
  import RunsPage from './RunsPage.svelte';
  import RunDetailPage from './RunDetailPage.svelte';
  import MessagePage from './MessagePage.svelte';
  import ComparePage from './ComparePage.svelte';
  import MessageRunsPage from './MessageRunsPage.svelte';
  import JobsPage from './JobsPage.svelte';
  import JobDetailPage from './JobDetailPage.svelte';
  import VariantsPage from './VariantsPage.svelte';
  import VariantEditorPage from './VariantEditorPage.svelte';

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
{:else if name === 'testingJobs'}
  <JobsPage />
{:else if name === 'testingJob'}
  <JobDetailPage />
{:else if name === 'testingVariants'}
  <VariantsPage />
{:else if name === 'testingVariant'}
  <VariantEditorPage />
{/if}
