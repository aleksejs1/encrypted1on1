import { describe, expect, it } from 'vitest';
import { reportingLine, roleContradiction, type MyOrg } from './reportingLine';

const org: MyOrg = {
  manager: { id: 'jordan' },
  directReports: [{ id: 'alex' }, { id: 'sam' }],
};

describe('reportingLine', () => {
  it('names my manager and my direct reports', () => {
    expect(reportingLine(org, 'jordan')).toBe('manager');
    expect(reportingLine(org, 'alex')).toBe('directReport');
    expect(reportingLine(org, 'sam')).toBe('directReport');
  });

  it('is null for a colleague who is neither', () => {
    expect(reportingLine(org, 'robin')).toBeNull();
  });

  it('is null with no colleague chosen', () => {
    expect(reportingLine(org, '')).toBeNull();
  });

  it('is null when the org structure could not be loaded', () => {
    expect(reportingLine(null, 'jordan')).toBeNull();
  });

  it('is null in a company with no reporting lines', () => {
    const none: MyOrg = { manager: null, directReports: [] };

    expect(reportingLine(none, 'jordan')).toBeNull();
    expect(reportingLine(none, '')).toBeNull();
  });

  it('is null for someone stored as both my manager and my report', () => {
    const loop: MyOrg = {
      manager: { id: 'jordan' },
      directReports: [{ id: 'jordan' }, { id: 'alex' }],
    };

    expect(reportingLine(loop, 'jordan')).toBeNull();
    expect(reportingLine(loop, 'alex')).toBe('directReport');
  });
});

describe('roleContradiction', () => {
  it('warns when I lead a 1:1 with my own manager', () => {
    expect(roleContradiction('manager', 'manager')).toBe('leadingMyManager');
  });

  it('warns when my own report leads the 1:1', () => {
    expect(roleContradiction('directReport', 'employee')).toBe('ledByMyReport');
  });

  it('is silent for the role that matches the reporting line', () => {
    expect(roleContradiction('manager', 'employee')).toBeNull();
    expect(roleContradiction('directReport', 'manager')).toBeNull();
  });

  it('is silent until a role is clicked', () => {
    expect(roleContradiction('manager', null)).toBeNull();
    expect(roleContradiction('directReport', null)).toBeNull();
  });

  it('is silent for a colleague who is neither, whatever the role', () => {
    expect(roleContradiction(null, 'manager')).toBeNull();
    expect(roleContradiction(null, 'employee')).toBeNull();
    expect(roleContradiction(null, null)).toBeNull();
  });
});
