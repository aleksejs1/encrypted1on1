/**
 * A beforeunload warning switched on and off by its owner, shared by the
 * private notes (notesUnloadWarning.ts) and the published-answers edit
 * (answersEdit.ts). Each call makes an independent warning with its own
 * listener. The listener is attached only while needed: Firefox keeps no
 * page with a beforeunload listener in its back-forward cache.
 *
 * `stillNeeded` is checked again when the tab is actually closing, for an
 * owner whose state can change without it calling back in time.
 */
export function unloadWarning(
  stillNeeded: () => boolean = () => true,
): (needed: boolean) => void {
  let attachedOn: Window | null = null;

  function onBeforeUnload(event: BeforeUnloadEvent): void {
    if (stillNeeded()) {
      event.preventDefault();
      event.returnValue = '';
    }
  }

  return (needed) => {
    if (needed && attachedOn !== window) {
      window.addEventListener('beforeunload', onBeforeUnload);
      attachedOn = window;
    } else if (!needed && attachedOn === window) {
      window.removeEventListener('beforeunload', onBeforeUnload);
      attachedOn = null;
    }
  };
}
