// Registr pojmenovaných form komponent (element typu `component`,
// backend TabBuilder::component). Klíč = `component_name` z form definice.
// Neznámé jméno → FormElement vykreslí placeholder `[name]`.
import FormAttachmentsView from './FormAttachmentsView.svelte';
import SentMessageTransport from './SentMessageTransport.svelte';
import PrintTextVariables from './PrintTextVariables.svelte';
import FormDocVatRecap from './FormDocVatRecap.svelte';
import FormDocParties from './FormDocParties.svelte';

// Komponenty s výškou podle vlastního obsahu (tabulka, karty). Sloupec
// složený jen z nich se **neroztahuje** na výšku tabu — fill mechanismus
// (`FormColumn`, docs/edit-forms.md §6) je pro komponenty s vlastním
// rolovacím oknem (náhledy příloh), které výšku berou od souseda.
export const flowFormComponents = new Set(['docVatRecap', 'docParties']);

export const formComponents = {
  attachmentsView: FormAttachmentsView,
  sentMessageTransport: SentMessageTransport,
  printTextVariables: PrintTextVariables,
  docVatRecap: FormDocVatRecap,
  docParties: FormDocParties,
};
