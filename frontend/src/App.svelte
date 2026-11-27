<script>
  import { authStore } from './stores/auth.svelte.js';
  import { authAction } from './stores/authAction.svelte.js';
  import { opAuth } from './stores/opAuth.svelte.js';
  import { accountPrefs } from './stores/accountPrefs.svelte.js';
  import { avatarStore } from './stores/avatar.svelte.js';
  import LoginScreen from './components/auth/LoginScreen.svelte';
  import SetPasswordScreen from './components/auth/SetPasswordScreen.svelte';
  import OpAuthScreen from './components/auth/OpAuthScreen.svelte';
  import AppShell from './components/layout/AppShell.svelte';
  import StatusScreen from './components/ui/StatusScreen.svelte';
  import { appInfoStore } from './stores/appInfo.svelte.js';
  import { nativeStore } from './stores/native.svelte.js';

  // Po úspěšném loginu načti per-user preference (vzhled, jazyk) ze serveru.
  function handleLoginSuccess() {
    accountPrefs.load();
    avatarStore.load();
  }
</script>

{#if appInfoStore.unavailable}
  <!-- Zavřený DS (suspended / maintenance / pending_deletion, #56): boot
       GET /_app/info vrátil 503 — stavová obrazovka místo čehokoli. -->
  <StatusScreen />
{:else if opAuth.txn}
  <!-- Pending OIDC OP schválení (D10) — větev PŘED rozhodováním
       portál/shell, funguje pro adminy i ne-adminy. Bez session nejdřív
       LoginScreen; po loginu se authStore přepne a approve proběhne. -->
  {#if authStore.isAuthenticated}
    <OpAuthScreen />
  {:else}
    <LoginScreen onSuccess={handleLoginSuccess} />
  {/if}
{:else if authStore.isAuthenticated}
  <!-- Jednotný shell pro všechny přihlášené (revize D10, task hosting-07):
       ne-admin dostává serverem ořezanou navigaci, portál je panel. -->
  <AppShell />
{:else if authAction.kind === 'set-password'}
  <SetPasswordScreen />
{:else}
  <LoginScreen onSuccess={handleLoginSuccess} />
{/if}

<!-- Simulace mostu nativní aplikace (#177 U11): zapnutá je vždy vidět —
     pruh a panel nad čímkoli, i nad přihlášením. Panel se načítá až se
     simulací (dynamický import), do hlavního chunku nepatří. -->
{#if nativeStore.mock}
  {#await import('./components/native/NativeMockPanel.svelte') then panel}
    <panel.default />
  {/await}
{/if}
