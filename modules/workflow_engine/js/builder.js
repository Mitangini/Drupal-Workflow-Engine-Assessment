/**
 * @file
 * Visual workflow builder for the Workflow Engine admin form.
 *
 * Keeps its whole state in the hidden [data-wfe-data] field as JSON. The server
 * re-validates everything on save (WorkflowLogic::validate()).
 */
(function (Drupal, once) {
  'use strict';

  const MAX_STEPS = 3;
  const TYPES = [
    ['text', 'Short text'],
    ['textarea', 'Long text'],
    ['email', 'Email address'],
    ['select', 'Dropdown list'],
    ['radio', 'Radio buttons'],
  ];
  const CHOICE = ['select', 'radio'];
  const LEVELS = [
    ['low', 'Low risk'],
    ['medium', 'Medium risk'],
    ['high', 'High risk'],
  ];

  // Static strings only: Drupal.t() HTML-escapes placeholder values, which would
  // show up literally in text nodes, so dynamic text is concatenated instead.
  const t = (s) => Drupal.t(s);
  const uid = (prefix) => prefix + Math.random().toString(36).slice(2, 8);
  const typeLabel = (type) => (TYPES.find((x) => x[0] === type) || [, type])[1];
  const isChoice = (q) => CHOICE.includes(q.type);
  const cleanOptions = (q) => [...new Set((q.options || []).map((o) => String(o).trim()).filter(Boolean))];
  const slugify = (s) => s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

  /**
   * Tiny DOM helper: h('div', {class: 'x', onclick: fn}, child, ...).
   */
  function h(tag, attrs, ...kids) {
    const el = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => {
      if (v === null || v === undefined || v === false) return;
      if (k === 'class') el.className = v;
      else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else el.setAttribute(k, v === true ? '' : v);
    });
    kids.flat(Infinity).forEach((c) => {
      if (c === null || c === undefined || c === false) return;
      el.append(c.nodeType ? c : document.createTextNode(String(c)));
    });
    return el;
  }

  function select(options, value, onchange, attrs) {
    const el = h('select', Object.assign({ onchange: (e) => onchange(e.target.value) }, attrs || {}));
    options.forEach(([val, label]) => {
      const o = h('option', { value: val }, label);
      if (String(val) === String(value)) o.selected = true;
      el.append(o);
    });
    return el;
  }

  function parse(raw) {
    let data;
    try { data = JSON.parse(raw); } catch (e) { data = {}; }
    data = data && typeof data === 'object' ? data : {};
    data.steps = Array.isArray(data.steps) && data.steps.length ? data.steps : [{ id: uid('s'), title: '', questions: [] }];
    data.steps.forEach((s) => {
      s.id = s.id || uid('s');
      s.title = s.title || '';
      s.questions = Array.isArray(s.questions) ? s.questions : [];
      s.questions.forEach((q) => {
        q.id = q.id || uid('q');
        q.label = q.label || '';
        q.type = q.type || 'text';
        q.required = !!q.required;
        q.options = Array.isArray(q.options) ? q.options : [];
      });
    });
    data.rules = Array.isArray(data.rules) ? data.rules : [];
    data.default_result = data.default_result || 'Assessment complete';
    return data;
  }

  function Builder(root, dataInput, form) {
    const state = parse(dataInput.value);
    const ui = { step: 0, open: new Set() };
    let timer = null;
    let checklistEl = null;

    // ---- queries -----------------------------------------------------------
    const flat = () => state.steps.flatMap((s, si) => s.questions.map((q, qi) => ({ q, si, qi })));
    const findQ = (id) => (flat().find((x) => x.q.id === id) || {}).q;
    const earlierChoice = (id) => {
      const all = flat();
      const at = all.findIndex((x) => x.q.id === id);
      return all.slice(0, at < 0 ? all.length : at).map((x) => x.q).filter(isChoice);
    };
    const qName = (q) => q.label.trim() || t('Untitled question');

    // ---- persistence & checks ---------------------------------------------
    function check() {
      const out = [];
      state.steps.forEach((s, si) => {
        const n = si + 1;
        if (!s.title.trim()) out.push(t('Step') + ' ' + n + ': ' + t('add a title.'));
        if (!s.questions.length) out.push(t('Step') + ' ' + n + ': ' + t('add at least one question.'));
        s.questions.forEach((q, qi) => {
          const nm = q.label.trim() ? '“' + q.label.trim() + '”' : t('Question') + ' ' + (qi + 1) + ' (' + t('step') + ' ' + n + ')';
          if (!q.label.trim()) out.push(nm + ': ' + t('write the question text.'));
          if (isChoice(q) && cleanOptions(q).length < 2) out.push(nm + ': ' + t('add at least two answer choices.'));
          if (q.condition) {
            const src = earlierChoice(q.id).find((x) => x.id === q.condition.question);
            if (!src || !q.condition.question || !q.condition.value) {
              out.push(nm + ': ' + t('finish the “only show if” setting, or turn it off.'));
            }
            else if (!cleanOptions(src).includes(q.condition.value)) {
              out.push(nm + ': ' + t('the answer it waits for no longer exists.'));
            }
          }
        });
      });
      state.rules.forEach((r, i) => {
        const src = findQ(r.question);
        const nm = t('Result rule') + ' ' + (i + 1);
        if (!r.question || !r.value || !r.level) out.push(nm + ': ' + t('choose a question, an answer and a result.'));
        else if (!src || !isChoice(src) || !cleanOptions(src).includes(r.value)) out.push(nm + ': ' + t('the answer no longer exists.'));
      });
      return out;
    }

    function save() {
      dataInput.value = JSON.stringify(state);
      paintChecklist();
    }

    function soon() {
      save();
      clearTimeout(timer);
      timer = setTimeout(renderKeepFocus, 450);
    }

    function change(fn) {
      fn();
      save();
      render();
    }

    // ---- rendering ---------------------------------------------------------
    function renderKeepFocus() {
      const a = document.activeElement;
      const key = a && a.dataset ? a.dataset.k : null;
      const pos = a && typeof a.selectionStart === 'number' ? a.selectionStart : null;
      render();
      if (key) {
        const n = root.querySelector('[data-k="' + key + '"]');
        if (n) {
          n.focus();
          if (pos !== null && n.setSelectionRange) {
            try { n.setSelectionRange(pos, pos); } catch (e) { /* ignore */ }
          }
        }
      }
    }

    function render() {
      if (ui.step >= state.steps.length) ui.step = state.steps.length - 1;
      checklistEl = h('div', { class: 'wfe-checklist', role: 'status', 'aria-live': 'polite' });
      root.replaceChildren(renderQuestionsSection(), renderRulesSection(), h('section', { class: 'wfe-section' }, checklistEl));
      paintChecklist();
    }

    function paintChecklist() {
      if (!checklistEl) return;
      const issues = check();
      const total = flat().length;
      const count = (n, one, many) => n + ' ' + (n === 1 ? t(one) : t(many));
      const summary = count(state.steps.length, 'step', 'steps') + ' · ' + count(total, 'question', 'questions') + ' · ' + count(state.rules.length, 'result rule', 'result rules');
      checklistEl.replaceChildren(
        h('div', { class: 'wfe-checklist__head' },
          h('strong', {}, issues.length ? t('Before you save') : t('Ready to save')),
          h('span', { class: 'wfe-muted' }, summary)),
        issues.length
          ? h('ul', {}, issues.map((i) => h('li', {}, i)))
          : h('p', { class: 'wfe-ok' }, '✓ ' + t('Everything looks good.')));
      checklistEl.classList.toggle('wfe-checklist--bad', issues.length > 0);
    }

    function renderQuestionsSection() {
      const step = state.steps[ui.step];
      const tabs = h('div', { class: 'wfe-tabs', role: 'tablist' },
        state.steps.map((s, i) => h('button', {
          type: 'button', role: 'tab', 'aria-selected': i === ui.step ? 'true' : 'false',
          class: 'wfe-tab' + (i === ui.step ? ' is-active' : ''),
          onclick: () => { ui.step = i; render(); },
        }, h('span', { class: 'wfe-tab__n' }, i + 1), h('span', {}, s.title.trim() || t('Untitled step')))),
        state.steps.length < MAX_STEPS
          ? h('button', {
            type: 'button', class: 'wfe-tab wfe-tab--add',
            onclick: () => change(() => { state.steps.push({ id: uid('s'), title: '', questions: [] }); ui.step = state.steps.length - 1; }),
          }, '+ ' + t('Add step'))
          : null);

      const panel = h('div', { class: 'wfe-panel', role: 'tabpanel' },
        h('div', { class: 'wfe-field' },
          h('label', { for: 'wfe-step-title' }, t('Step title')),
          h('input', {
            id: 'wfe-step-title', type: 'text', maxlength: 128, value: step.title, 'data-k': 'st:' + step.id,
            placeholder: t('For example: Applicant details'),
            oninput: (e) => { step.title = e.target.value; soon(); },
          })),
        step.questions.length
          ? step.questions.map((q, qi) => renderQuestion(step, q, qi))
          : h('div', { class: 'wfe-empty' }, h('strong', {}, t('No questions in this step yet.')), h('span', {}, t('Add the first question below.'))),
        h('div', { class: 'wfe-actions' },
          h('button', {
            type: 'button', class: 'wfe-btn wfe-btn--primary',
            onclick: () => change(() => {
              const q = { id: uid('q'), label: '', type: 'text', required: false, options: [] };
              step.questions.push(q);
              ui.open.add(q.id);
            }),
          }, '+ ' + t('Add question')),
          state.steps.length > 1
            ? h('button', {
              type: 'button', class: 'wfe-btn wfe-btn--danger',
              onclick: () => {
                if (!window.confirm(t('Remove this step and all of its questions?'))) return;
                change(() => { state.steps.splice(ui.step, 1); ui.step = Math.max(0, ui.step - 1); });
              },
            }, t('Remove this step'))
            : null));

      return h('section', { class: 'wfe-section' },
        h('header', { class: 'wfe-section__head' },
          h('h2', {}, '1 · ' + t('Questions')),
          h('p', {}, t('Visitors go through your steps one at a time. You can have up to three.'))),
        tabs, panel);
    }

    function renderQuestion(step, q, qi) {
      const open = ui.open.has(q.id);
      const move = (dir) => change(() => {
        const j = qi + dir;
        if (j < 0 || j >= step.questions.length) return;
        [step.questions[qi], step.questions[j]] = [step.questions[j], step.questions[qi]];
      });
      const head = h('div', { class: 'wfe-q__head' },
        h('button', {
          type: 'button', class: 'wfe-q__toggle', 'aria-expanded': open ? 'true' : 'false',
          onclick: () => { if (open) ui.open.delete(q.id); else ui.open.add(q.id); render(); },
        },
        h('span', { class: 'wfe-q__num' }, qi + 1),
        h('span', { class: 'wfe-q__title' }, qName(q)),
        h('span', { class: 'wfe-chip' }, typeLabel(q.type)),
        q.required ? h('span', { class: 'wfe-chip wfe-chip--req' }, t('Required')) : null,
        q.condition ? h('span', { class: 'wfe-chip wfe-chip--cond' }, t('Conditional')) : null),
        h('div', { class: 'wfe-q__tools' },
          h('button', { type: 'button', class: 'wfe-icon', title: t('Move up'), 'aria-label': t('Move up'), disabled: qi === 0 ? '' : null, onclick: () => move(-1) }, '↑'),
          h('button', { type: 'button', class: 'wfe-icon', title: t('Move down'), 'aria-label': t('Move down'), disabled: qi === step.questions.length - 1 ? '' : null, onclick: () => move(1) }, '↓'),
          h('button', {
            type: 'button', class: 'wfe-icon wfe-icon--danger', title: t('Delete question'), 'aria-label': t('Delete question'),
            onclick: () => change(() => { step.questions.splice(qi, 1); ui.open.delete(q.id); }),
          }, '×')));

      const card = h('div', { class: 'wfe-q' + (open ? ' is-open' : '') }, head);
      if (!open) return card;

      const body = h('div', { class: 'wfe-q__body' },
        h('div', { class: 'wfe-field' },
          h('label', {}, t('Question')),
          h('input', {
            type: 'text', maxlength: 255, value: q.label, 'data-k': 'ql:' + q.id,
            placeholder: t('For example: What is your full name?'),
            oninput: (e) => { q.label = e.target.value; soon(); },
          })),
        h('div', { class: 'wfe-row' },
          h('div', { class: 'wfe-field' },
            h('label', {}, t('Answer type')),
            select(TYPES, q.type, (v) => change(() => {
              q.type = v;
              if (CHOICE.includes(v) && cleanOptions(q).length === 0) q.options = ['', ''];
            }))),
          h('label', { class: 'wfe-switch' },
            h('input', { type: 'checkbox', checked: q.required ? '' : null, onchange: (e) => change(() => { q.required = e.target.checked; }) }),
            h('span', {}, t('Answer is required')))),
        isChoice(q) ? renderChoices(q) : null,
        renderCondition(q));
      card.append(body);
      return card;
    }

    function renderChoices(q) {
      if (!q.options.length) q.options = ['', ''];
      const list = h('div', { class: 'wfe-choices' },
        q.options.map((o, i) => h('div', { class: 'wfe-choice' },
          h('input', {
            type: 'text', maxlength: 255, value: o, 'data-k': 'qo:' + q.id + ':' + i,
            placeholder: t('Answer') + ' ' + (i + 1),
            oninput: (e) => { q.options[i] = e.target.value; soon(); },
            onkeydown: (e) => {
              if (e.key === 'Enter') {
                e.preventDefault();
                q.options.splice(i + 1, 0, '');
                save();
                render();
                const n = root.querySelector('[data-k="qo:' + q.id + ':' + (i + 1) + '"]');
                if (n) n.focus();
              }
            },
          }),
          h('button', {
            type: 'button', class: 'wfe-icon wfe-icon--danger', title: t('Remove answer'), 'aria-label': t('Remove answer'),
            onclick: () => change(() => { q.options.splice(i, 1); }),
          }, '×'))));
      return h('div', { class: 'wfe-field' },
        h('label', {}, t('Answer choices')),
        list,
        h('button', { type: 'button', class: 'wfe-btn wfe-btn--ghost', onclick: () => change(() => { q.options.push(''); }) }, '+ ' + t('Add choice')));
    }

    function renderCondition(q) {
      const earlier = earlierChoice(q.id);
      const on = !!q.condition;
      const toggle = h('label', { class: 'wfe-switch' },
        h('input', {
          type: 'checkbox', checked: on ? '' : null,
          onchange: (e) => change(() => {
            if (!e.target.checked) { delete q.condition; return; }
            const src = earlier[earlier.length - 1];
            q.condition = { question: src ? src.id : '', value: src ? (cleanOptions(src)[0] || '') : '' };
          }),
        }),
        h('span', {}, t('Only show this question when an earlier answer matches')));
      if (!on) return h('div', { class: 'wfe-cond' }, toggle);

      const src = earlier.find((x) => x.id === q.condition.question);
      const body = earlier.length
        ? h('div', { class: 'wfe-sentence' },
          h('span', {}, t('Show when')),
          select([['', t('Choose a question')]].concat(earlier.map((x) => [x.id, qName(x)])), src ? src.id : '', (v) => change(() => {
            q.condition.question = v;
            const s = findQ(v);
            q.condition.value = s ? (cleanOptions(s)[0] || '') : '';
          })),
          h('span', {}, t('is')),
          select([['', t('Choose an answer')]].concat(src ? cleanOptions(src).map((o) => [o, o]) : []), q.condition.value, (v) => change(() => { q.condition.value = v; })))
        : h('p', { class: 'wfe-hint' }, t('Add a dropdown or radio-button question earlier in the workflow first, then come back here.'));
      return h('div', { class: 'wfe-cond is-on' }, toggle, body);
    }

    function renderRulesSection() {
      const choiceQs = flat().map((x) => x.q).filter(isChoice);
      const rules = state.rules.map((r, i) => {
        const src = findQ(r.question);
        const answers = src && isChoice(src) ? cleanOptions(src) : [];
        return h('div', { class: 'wfe-rule wfe-rule--' + (r.level || 'none') },
          h('div', { class: 'wfe-sentence' },
            h('span', {}, t('When the answer to')),
            select([['', t('Choose a question')]].concat(choiceQs.map((x) => [x.id, qName(x)])), src && isChoice(src) ? src.id : '', (v) => change(() => {
              r.question = v;
              const s = findQ(v);
              r.value = s ? (cleanOptions(s)[0] || '') : '';
            })),
            h('span', {}, t('is')),
            select([['', t('Choose an answer')]].concat(answers.map((o) => [o, o])), r.value, (v) => change(() => { r.value = v; })),
            h('span', {}, t('show')),
            select([['', t('Choose a result')]].concat(LEVELS), r.level, (v) => change(() => { r.level = v; }))),
          h('div', { class: 'wfe-field' },
            h('label', {}, t('Message shown with the result (optional)')),
            h('textarea', {
              rows: 2, maxlength: 1000, 'data-k': 'rm:' + r.id,
              oninput: (e) => { r.message = e.target.value; save(); },
            }, r.message || '')),
          h('button', {
            type: 'button', class: 'wfe-btn wfe-btn--danger wfe-btn--small',
            onclick: () => change(() => { state.rules.splice(i, 1); }),
          }, t('Remove rule')));
      });

      return h('section', { class: 'wfe-section' },
        h('header', { class: 'wfe-section__head' },
          h('h2', {}, '2 · ' + t('Results')),
          h('p', {}, t('Choose what people see based on their answers. If several rules match, the most serious result wins.'))),
        rules.length ? rules : h('div', { class: 'wfe-empty' }, h('strong', {}, t('No result rules yet.')), h('span', {}, choiceQs.length ? t('Add a rule to show a risk level.') : t('Rules use dropdown or radio-button questions. Add one first.'))),
        h('div', { class: 'wfe-actions' },
          h('button', {
            type: 'button', class: 'wfe-btn wfe-btn--primary', disabled: choiceQs.length ? null : '',
            onclick: () => change(() => { state.rules.push({ id: uid('r'), question: '', value: '', level: 'high', message: '' }); }),
          }, '+ ' + t('Add result rule'))),
        h('div', { class: 'wfe-field wfe-default' },
          h('label', {}, t('If no rule matches, show')),
          h('input', {
            type: 'text', maxlength: 128, value: state.default_result,
            oninput: (e) => { state.default_result = e.target.value; save(); },
          })));
    }

    // ---- wiring ------------------------------------------------------------
    // Enter in a text box must not submit the whole form.
    root.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type === 'text') e.preventDefault();
    });
    form.addEventListener('submit', (e) => {
      save();
      if (check().length && checklistEl) {
        e.preventDefault();
        if (checklistEl.scrollIntoView) checklistEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        checklistEl.classList.add('wfe-shake');
        setTimeout(() => checklistEl.classList.remove('wfe-shake'), 600);
      }
    });

    // Fill in the web address from the name until the editor changes it.
    const nameInput = form.querySelector('[name="label"]');
    const slugInput = form.querySelector('[data-wfe-slug]');
    if (nameInput && slugInput) {
      let auto = !slugInput.value;
      nameInput.addEventListener('input', () => { if (auto) slugInput.value = slugify(nameInput.value); });
      slugInput.addEventListener('input', () => { auto = false; });
    }

    // Open the first question of a brand new workflow to invite the first click.
    if (flat().length === 0) ui.open.clear();
    render();
  }

  Drupal.behaviors.workflowEngineBuilder = {
    attach(context) {
      once('wfe-builder', '[data-wfe-builder]', context).forEach((root) => {
        const form = root.closest('form');
        const dataInput = form && form.querySelector('[data-wfe-data]');
        if (form && dataInput) Builder(root, dataInput, form);
      });
    },
  };
}(Drupal, once));
