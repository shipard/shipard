// Registr pojmenovaných form komponent (element typu `component`,
// backend TabBuilder::component). Klíč = `component_name` z form definice.
// Neznámé jméno → FormElement vykreslí placeholder `[name]`.
import FormAttachmentsView from './FormAttachmentsView.svelte';
import SentMessageTransport from './SentMessageTransport.svelte';
import PrintTextVariables from './PrintTextVariables.svelte';

export const formComponents = {
  attachmentsView: FormAttachmentsView,
  sentMessageTransport: SentMessageTransport,
  printTextVariables: PrintTextVariables,
};
