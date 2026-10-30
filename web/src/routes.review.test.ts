import { describe, expect, it } from 'vitest';
import { matchRoute } from './router';

describe('the router the route table leaves alone', () => {
  it.each([
    ['', 'up'],
    ['down', 'down'],
    ['up', 'up'],
    ['DOWN', 'up'],
    [' down', 'up'],
    ['down ', 'up'],
    ['__proto__', 'up'],
    ['sideways', 'up'],
  ])('matches /movers?direction=%j to the %s list, never to another value', (value, expected) => {
    const route = matchRoute('/movers', `?direction=${encodeURIComponent(value)}`);
    expect(route).toEqual({ name: 'movers', direction: expected });
  });

  it('takes the first direction when the query repeats it, as it always has', () => {
    expect(matchRoute('/movers', '?direction=down&direction=up')).toEqual({
      name: 'movers',
      direction: 'down',
    });
    expect(matchRoute('/movers', '?direction=up&direction=down')).toEqual({
      name: 'movers',
      direction: 'up',
    });
  });
});
