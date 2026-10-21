/*
 * Libellés communs des échéances au compteur (suivi /meters, écran mural, supervision de l'encodeur) :
 * bornes de l'avancement (dernière réalisation → échéance), reste, date prévue, raison d'un « à vérifier ».
 */
(function (Core) {
  'use strict';

  var t = window.PM.t;
  var locale = window.PM.locale;

  function num(value, decimals) { return Core.formatNumber(value, locale, decimals); }
  function value(n, unit) { return n === null || n === undefined ? '—' : num(n, 0) + ' ' + unit; }

  /** « Dernière : 1 000 km (12/10) » — la date n'est connue que si le départ a été saisi. */
  function lastDone(plan) {
    if (plan.start === null) return t('js.meters.last_done', { value: '—' });
    return plan.start_confirmed && plan.last_done_at
      ? t('js.meters.last_done_on', { value: value(plan.start, plan.unit), date: Core.formatDate(plan.last_done_at, locale) })
      : t('js.meters.last_done', { value: value(plan.start, plan.unit) });
  }

  function nextDue(plan) { return t('js.meters.next_at', { value: value(plan.next_due, plan.unit) }); }

  /** Bornes de l'avancement sur une ligne : « Dernière : 1 000 km (12/10) → Échéance : 10 000 km ». */
  function bounds(plan) { return lastDone(plan) + ' → ' + nextDue(plan); }

  function remaining(plan) {
    if (plan.remaining === null) return '—';
    if (plan.remaining < 0) return t('js.meters.over', { n: num(-plan.remaining, 0), unit: plan.unit });
    if (plan.remaining === 0) return t('js.meters.reached');
    return value(plan.remaining, plan.unit);
  }

  function when(plan) {
    if (plan.remaining === null) return '';
    if (plan.remaining <= 0) return t('js.meters.at_hours', { n: num(plan.next_due, 0), unit: plan.unit });
    return plan.due_on === null ? t('js.meters.no_date') : Core.formatDate(plan.due_on, locale) + ' · ' + t('js.meters.in_days', { n: plan.days_left });
  }

  /** Raison d'une situation non établie (null si tout est établi). */
  function issue(plan, row) {
    if (!plan || !plan.issue) return null;
    if (plan.issue === 'mismatch') {
      return t('js.meters.issue.mismatch', { plan: plan.unit, engine: row ? row.unit : (plan.unit === 'h' ? 'km' : 'h') });
    }
    return {
      unconfirmed: t('js.meters.issue.unconfirmed'),
      stale: t('js.meters.issue.stale'),
      no_rate: t('js.meters.issue.no_rate'),
      no_index: t('js.meters.issue.no_index')
    }[plan.issue] || null;
  }

  window.PMMeters = { value: value, lastDone: lastDone, nextDue: nextDue, bounds: bounds, remaining: remaining, when: when, issue: issue };
})(window.PMCore);
