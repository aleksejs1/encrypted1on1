<script lang="ts">
  const { size = 24 }: { size?: number } = $props();
</script>

<div class="logo-mark" style="width:{size}px; height:{size}px;">
  <div class="logo-circle logo-circle-accent"></div>
  <div class="logo-circle logo-circle-accent-2"></div>
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="logo-lock">
    <rect x="3" y="11" width="18" height="10" rx="2"></rect>
    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
  </svg>
</div>

<style>
  .logo-mark {
    position: relative;
    flex: none;
  }
  .logo-circle {
    position: absolute;
    width: 68%;
    height: 68%;
    border-radius: 50%;
    opacity: 0.9;
  }
  .logo-circle-accent {
    inset: 0;
    background: var(--color-accent);
  }
  .logo-circle-accent-2 {
    right: 0;
    bottom: 0;
    background: var(--color-accent-2);
  }
  .logo-lock {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 42%;
    height: 42%;
    color: var(--color-bg);
  }
</style>
