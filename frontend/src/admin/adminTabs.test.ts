import { describe, expect, it } from 'vitest';
import { adminTabs } from './adminTabs';

describe('adminTabs', () => {
  it('has no Plan tab on a self-hosted instance', () => {
    expect(adminTabs(false).map((tab) => tab.key)).toEqual([
      'users',
      'reports',
      'invites',
      'templates',
    ]);
  });

  it('ends with the Plan tab in the Cloud deployment', () => {
    const tabs = adminTabs(true);
    expect(tabs.map((tab) => tab.key)).toEqual([
      'users',
      'reports',
      'invites',
      'templates',
      'plan',
    ]);
    expect(tabs.at(-1)).toEqual({
      key: 'plan',
      href: '/admin/plan',
      labelKey: 'admin.planTab',
    });
  });
});
