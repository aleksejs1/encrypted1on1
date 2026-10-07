import { describe, expect, it } from 'vitest';
import { archiveConfirmation } from './archiveConfirmation';

describe('archiveConfirmation', () => {
  it('asks for a plain confirmation when both sides are published', () => {
    expect(
      archiveConfirmation({
        myPublished: true,
        counterpartPublished: true,
        myDraftHasAnswers: false,
      }),
    ).toEqual({ publishFirst: false, unpublished: 'nobody' });
  });

  it('warns that the counterpart has not published', () => {
    expect(
      archiveConfirmation({
        myPublished: true,
        counterpartPublished: false,
        myDraftHasAnswers: false,
      }),
    ).toEqual({ publishFirst: false, unpublished: 'counterpart' });
  });

  it('publishes my unpublished answers first', () => {
    expect(
      archiveConfirmation({
        myPublished: false,
        counterpartPublished: true,
        myDraftHasAnswers: true,
      }),
    ).toEqual({ publishFirst: true, unpublished: 'nobody' });
  });

  it('still warns about the counterpart when publishing mine first', () => {
    expect(
      archiveConfirmation({
        myPublished: false,
        counterpartPublished: false,
        myDraftHasAnswers: true,
      }),
    ).toEqual({ publishFirst: true, unpublished: 'counterpart' });
  });

  it('lets an empty unpublished side close, with a warning', () => {
    expect(
      archiveConfirmation({
        myPublished: false,
        counterpartPublished: true,
        myDraftHasAnswers: false,
      }),
    ).toEqual({ publishFirst: false, unpublished: 'me' });
  });

  it('lets a meeting nobody filled in close, with a warning', () => {
    expect(
      archiveConfirmation({
        myPublished: false,
        counterpartPublished: false,
        myDraftHasAnswers: false,
      }),
    ).toEqual({ publishFirst: false, unpublished: 'both' });
  });

  it('ignores draft content once my side is published', () => {
    expect(
      archiveConfirmation({
        myPublished: true,
        counterpartPublished: true,
        myDraftHasAnswers: true,
      }),
    ).toEqual({ publishFirst: false, unpublished: 'nobody' });
  });
});
