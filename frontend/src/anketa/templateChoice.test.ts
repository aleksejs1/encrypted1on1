import { describe, expect, it } from 'vitest';
import {
  choiceDescription,
  customChoice,
  customTemplateIdOf,
  defaultNextChoice,
  nextTemplateFields,
  templateFields,
  type TemplateChoice,
} from './templateChoice';

describe('customChoice / customTemplateIdOf', () => {
  it('round-trips a template id', () => {
    const choice = customChoice('0199a-template');
    expect(choice).toBe('custom:0199a-template');
    expect(customTemplateIdOf(choice)).toBe('0199a-template');
  });

  it('names no company template for a built-in key', () => {
    expect(customTemplateIdOf('regular')).toBeNull();
    expect(customTemplateIdOf('career_growth')).toBeNull();
  });
});

describe('choiceDescription', () => {
  const companyTemplates = [
    { id: 't1', name: 'Sprint review', description: 'After every sprint.' },
    { id: 't2', name: 'No description', description: '' },
  ];

  it("is a built-in template's description key", () => {
    expect(choiceDescription('regular', companyTemplates)).toEqual({
      key: 'createAnketa.templateRegularDescription',
    });
    expect(choiceDescription('career_growth', [])).toEqual({
      key: 'createAnketa.templateCareerGrowthDescription',
    });
  });

  it("is nothing for a built-in key this build doesn't know", () => {
    expect(
      choiceDescription('from_a_newer_server' as TemplateChoice, []),
    ).toBeNull();
  });

  it("is a company template's own text", () => {
    expect(choiceDescription(customChoice('t1'), companyTemplates)).toEqual({
      text: 'After every sprint.',
    });
  });

  it('is nothing for a company template without a description', () => {
    expect(choiceDescription(customChoice('t2'), companyTemplates)).toBeNull();
  });

  it('is nothing for a company template missing from the list', () => {
    expect(choiceDescription(customChoice('t3'), companyTemplates)).toBeNull();
    expect(choiceDescription(customChoice('t1'), [])).toBeNull();
  });
});

describe('templateFields', () => {
  it('sends a built-in key alone', () => {
    expect(templateFields('onboarding')).toEqual({ templateKey: 'onboarding' });
  });

  it('sends a company template as the custom key and its id', () => {
    expect(templateFields(customChoice('t1'))).toEqual({
      templateKey: 'custom',
      customTemplateId: 't1',
    });
  });
});

describe('defaultNextChoice', () => {
  it('is the built-in key', () => {
    expect(
      defaultNextChoice({
        nextCycleTemplateKey: 'regular',
        nextCustomTemplateId: null,
      }),
    ).toBe('regular');
  });

  it('is the company template for a custom default', () => {
    expect(
      defaultNextChoice({
        nextCycleTemplateKey: 'custom',
        nextCustomTemplateId: 't1',
      }),
    ).toBe('custom:t1');
  });

  it('is null without a successor', () => {
    expect(
      defaultNextChoice({
        nextCycleTemplateKey: null,
        nextCustomTemplateId: null,
      }),
    ).toBeNull();
    // Never served, but not a template either.
    expect(
      defaultNextChoice({
        nextCycleTemplateKey: 'custom',
        nextCustomTemplateId: null,
      }),
    ).toBeNull();
  });
});

describe('nextTemplateFields', () => {
  it('sends nothing while the choice is the default', () => {
    expect(nextTemplateFields('regular', 'regular')).toEqual({});
    expect(nextTemplateFields('custom:t1', 'custom:t1')).toEqual({});
    expect(nextTemplateFields(null, null)).toEqual({});
  });

  it('sends a changed built-in choice as its key', () => {
    expect(nextTemplateFields('career_growth', 'custom:t1')).toEqual({
      nextTemplateKey: 'career_growth',
    });
  });

  it('sends a changed company template as the custom key and its id', () => {
    expect(nextTemplateFields('custom:t2', 'regular')).toEqual({
      nextTemplateKey: 'custom',
      nextCustomTemplateId: 't2',
    });
    expect(nextTemplateFields('custom:t2', 'custom:t1')).toEqual({
      nextTemplateKey: 'custom',
      nextCustomTemplateId: 't2',
    });
  });
});
