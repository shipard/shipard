<script>
  // Obrazovka s kartou uprostřed (#133): přihlášení, nastavení hesla,
  // schválení OIDC transakce a stavová obrazovka zavřeného zdroje dat.
  // Obal přes celou obrazovku + plovoucí plocha (zaoblení lg + stín, D35);
  // obsah karty dává obrazovka jako `children` a styluje si ho sama.
  //
  // Obal má odsazení ze všech stran, takže na telefonu karta nedoléhá ke
  // krajům a zůstává kartou. `100dvh` = viditelná výška na mobilu (bez
  // adresního řádku); prohlížeč, který `dvh` nezná, deklaraci zahodí
  // a platí `100vh` nad ní.
  //
  // `testid` jde na obal (`StatusScreen` → `status-screen`).
  // Pravidla: docs/design-system.md §8 Obrazovka s kartou uprostřed.
  let { testid = undefined, children } = $props();
</script>

<div class="shpd-centered" data-testid={testid}>
  <div class="shpd-centered__card">
    {@render children?.()}
  </div>
</div>

<style>
  .shpd-centered {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    min-height: 100dvh;
    padding: var(--shpd-space-md);
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-centered__card {
    width: 100%;
    max-width: 25rem;
    padding: var(--shpd-space-xl);
    background-color: var(--shpd-color-bg);
    border-radius: var(--shpd-radius-lg);
    box-shadow: var(--shpd-shadow-md);
  }
</style>
