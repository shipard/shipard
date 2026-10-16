<script>
  // Potvrzení akce dev dashboardu nad DevDialog: zpráva, Potvrdit / Zpět,
  // `variant` danger pro destruktivní akce (reseed, zrušení úlohy).
  import Button from '../../components/ui/Button.svelte';
  import DevDialog from './DevDialog.svelte';

  let {
    open = false,
    title = '',
    message = '',
    confirmLabel = 'Potvrdit',
    cancelLabel = 'Zpět',
    variant = 'primary',
    busy = false,
    onConfirm,
    onCancel,
  } = $props();
</script>

<DevDialog {open} {title} width="480px" onClose={() => { if (!busy) onCancel?.(); }}>
  <div class="dev-dialog__message">{message}</div>
  {#snippet footer()}
    <Button label={cancelLabel} variant="secondary" size="sm" disabled={busy} onclick={() => onCancel?.()} />
    <Button label={confirmLabel} variant={variant === 'danger' ? 'danger' : 'primary'} size="sm" loading={busy} disabled={busy} onclick={() => onConfirm?.()} />
  {/snippet}
</DevDialog>
