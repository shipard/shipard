<script>
  import { router, onLinkClick } from '../router.svelte.js';
</script>

<div class="dev-page__head">
  <h1>Stránka nenalezena</h1>
</div>
<div class="dev-card">
  <p>Tahle adresa v dashboardu nic nezobrazuje.</p>
  <p><a href={router.href('dataSources')} onclick={onLinkClick}>Zpět na zdroje dat</a></p>
</div>
