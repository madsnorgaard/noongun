# Design reference

The owner's interface and brand guide, translated into what a developer needs.
The guide itself is a design canvas kept outside the repository. This file is the
reference for every milestone: use the names below, never a raw value.

Where the guide and `CLAUDE.md` disagree, `CLAUDE.md` wins. The last section lists
where that happens and where the built system differs from what the guide draws.

## The idea

Cape Town has set its watches by the Signal Hill cannon since 1806. The mark is a
clock with both hands at twelve and one shot leaving the dial. A post is loaded,
has a firing time, and fires. The tagline is "On time, every time."

The cannon is for moments, not for labels: states keep their plain words.

## Colour

All colours are CSS variables in `resources/css/app.css`, exposed to Tailwind
through `@theme`. Pages use the Tailwind name.

| Guide | Value | Variable | Tailwind | Use |
|---|---|---|---|---|
| Powder | `#F5F5F7` | `--background` | `bg-background` | Page ground |
| Paper | `#FFFFFF` | `--card`, `--popover` | `bg-card` | Cards, dialogs, fields |
| Ink | `#1D1D1F` | `--foreground` | `text-foreground` | Text |
| Secondary text | `#55555A` | `--muted-foreground` | `text-muted-foreground` | Labels, hints, counts |
| Gunmetal | `#15171A` | `--primary` | `bg-primary` | The ordinary filled button, chrome |
| Signal red | `#D4301F` | `--signal` | `bg-signal` | The one red action, the logo tile |
| Signal red, hover | `#BC2A1B` | `--signal-hover` | `bg-signal-hover` | Hover of the red action |
| Tag grey | `#F0F0F3` | `--secondary`, `--muted` | `bg-secondary` | Channel tags, Draft chip, quiet rows |
| Hover grey | `#E8E8ED` | `--accent` | `bg-accent` | Hover, segmented control track |
| Divider | `#E8E8ED` | `--border` | `border-border` | Lines inside cards |
| Control border | `#8A8A90` | `--input` | `border-input` | Fields, outline buttons |
| Failed | `#B3261E` | `--destructive`, `--destructive-fill` | `text-destructive`, `bg-destructive-fill` | Failed text, border and chip |
| Harbour green | `#1B7A45` | `--success`, `--success-fill` | `text-success`, `bg-success-fill` | Published, connected, OK |
| Dark brass | `#8A5A00` | `--warning`, `--warning-fill` | `text-warning` | Advice that needs a look |
| Advice surface | `#FFF4D6` | `--warning-surface` | `bg-warning-surface` | Background of an advice row |
| Brass | `#E0B43C` | `--brass` | `bg-brass` | The fuse, the Approved dot |
| Idle grey | `#8E8E93` | `--idle` | `bg-idle` | The Draft dot |
| Nav ground | `#EBEBEF` | `--sidebar` | `bg-sidebar` | Sidebar |
| Nav hover | `#E1E1E6` | `--sidebar-accent` | `bg-sidebar-accent` | Sidebar hover |
| Panel | `#15171A` | `--panel` | `bg-panel` | Next firing, a scheduled post |
| Panel secondary text | `#B9B9C0` | `--panel-muted` | `text-panel-muted` | Labels on a panel |
| Panel track | `#2C2F34` | `--panel-track` | `bg-panel-track` | The unburnt fuse |

Rules:

- `text-destructive`, `text-success` and `text-warning` are text and border
  colours. The `-fill` names are backgrounds that carry white text. Do not swap
  them: in the dark theme they are different shades.
- Brass is never text and never the only signal on a light surface (1.9:1 on
  Paper). It is a dot next to a word, or the fuse on a panel.
- The control border is darker than the guide's `#C7C7CC`, which is 1.7:1 on
  Paper. `#8A8A90` gives 3.4:1 on Paper and 3.2:1 on Powder.

### Dark theme

The guide draws a light interface only. The dark theme keeps every role and swaps
the surfaces, using the greys the guide already puts on its gunmetal panels.

| Role | Light | Dark |
|---|---|---|
| Ground | `#F5F5F7` | `#15171A` (Gunmetal) |
| Card | `#FFFFFF` | `#1F2226` |
| Text | `#1D1D1F` | `#F5F5F7` |
| Secondary text | `#55555A` | `#B9B9C0` |
| Ordinary filled button | `#15171A`, white text | `#F5F5F7`, gunmetal text |
| Tag grey, track | `#F0F0F3` | `#2C2F34` |
| Hover | `#E8E8ED` | `#353940` |
| Divider | `#E8E8ED` | `#2C2F34` |
| Control border | `#8A8A90` | `#74787F` |
| Failed text | `#B3261E` | `#FF8A7A` |
| Published text | `#1B7A45` | `#6FCF97` |
| Advice text | `#8A5A00` | `#E0B43C` |
| Panel | `#15171A` | `#2C2F34`, track `#15171A` |
| Sidebar | `#EBEBEF` | `#101214` |

Signal red, Brass and the three `-fill` colours are the same in both themes.

## Type

Instrument Sans for everything, JetBrains Mono (`font-mono`) for times, counts and
ids. Both are served from this instance: the `@fontsource` packages are read at
build time by the fonts option in `vite.config.ts`, with `font-display: swap`. No
font service is called, at build time or in the browser.

Weights shipped: Instrument Sans 400, 600, 700. JetBrains Mono 400, 500. There is
no 500 cut of Instrument Sans, so `font-medium` is set to 600. Use `font-semibold`
in new code.

| Tailwind | Size | Use |
|---|---|---|
| `text-display` | 38px, 700, -0.03em | Page title on a wide screen |
| `text-title` | 28px, 700, -0.025em | Page title on a phone, editor title |
| `text-heading` | 22px, 700, -0.02em | Section and card headings |
| `text-xl` | 20px | The product name next to the mark |
| `text-lg` | 18px | The text being written, the line on a panel |
| `text-body` | 17px, line height 1.5 | Body. This is the default on `body` |
| `text-base` | 16px | Buttons, fields, explanations |
| `text-sm` | 15px | Labels, hints, secondary lines |
| `text-[0.875rem]` | 14px, 600 | State chips only |
| `text-xs` | 13px | Tags, small mono, ids |

`text-xs` and `text-sm` replace the Tailwind sizes of the same name (12px and
14px). Nothing is set smaller than 13px. Post text is never wider than `72ch`.

## Shape and space

| Tailwind | Radius | Use |
|---|---|---|
| `rounded-sm` | 8px | Channel tags, calendar entries |
| `rounded-[10px]` | 10px | Sidebar items, segments of a segmented control |
| `rounded-md` | 12px | Buttons, fields |
| `rounded-lg` | 14px | Text areas, alert boxes, list rows, the large button |
| `rounded-xl` | 20px | Cards |
| `rounded-2xl` | 24px | The main card of a page, the gunmetal panel |
| `rounded-full` | - | State chips, dots, the fuse |

- Page padding: `px-4 pt-2 pb-12 md:px-8 lg:px-11`. Never less than 16px at the side.
- Gap between the blocks of a page: `gap-5.5` (22px). Card padding: `p-5.5`.
- Cards have no border. They sit on Powder with `shadow-xs`.
- Sidebar: 224px wide.

## Controls

`Button` (`resources/js/components/ui/button`) carries the shapes. Do not restyle
it on a page.

| Variant | Look | Use |
|---|---|---|
| `signal` | Signal red, white text | The one red action of a view |
| `default` | Gunmetal, white text | Every other filled button |
| `outline` | Paper, control border | Edit, navigation, the second choice |
| `ghost` | Text only | Add `text-muted-foreground` for Reject and other ways out |
| `destructive` | Failed red, white text | Only inside a dialog that confirms removal |

| Size | Height | Use |
|---|---|---|
| `default` | 46px | Page level buttons |
| `sm` | 44px | Buttons inside rows and cards |
| `lg` | 50px, 14px radius | The red action in a side card |
| `icon`, `icon-sm` | 46px, 44px square | Icon buttons |

Fields (`Input`, `NativeSelect`) are 46px high, Paper, 12px radius, 16px text.
Labels are 15px in secondary text colour, above the field.

### One red action per view

"One red action per view, and it is always the one that moves the week forward."

- Red is never the default. A button is red only because a page asked for
  `variant="signal"`, and a page asks at most once.
- Today: "Approve N selected" on the queue, "Approve" on a draft, "Schedule" on a
  post that is approved and has no firing time.
- Saving, connecting, signing in and confirming a dialog are gunmetal.
- Removing something is never Signal red.

## States

One word and one dot. The word carries the meaning, the dot repeats it.
`PostStateBadge` draws the chip, `resources/js/lib/postStates.ts` holds the styles.

| State | Word | Chip | Dot | In one line |
|---|---|---|---|---|
| `draft` | Draft | Tag grey | Idle grey | Written by you or proposed from your brief. Nothing leaves the building from here. |
| `approved` | Approved | Outlined | Hollow | You said yes. It has no firing time yet. |
| `scheduled` | Scheduled | Gunmetal | Brass | You said yes and it has a firing time. |
| `publishing` | Publishing | Gunmetal | Brass, pulsing | It is going out now. |
| `published` | Published | Harbour green | White | Out on time, with a link to the live post. |
| `failed` | Failed | Failed red | White, square | The platform refused it. You are told why in plain language and get one button to put it right. |
| `rejected` | Rejected | Tag grey, secondary text | Hollow grey | Turned down. Kept for reference. |

The guide names four states: Draft, Approved, Published, Failed. See "Where the
guide and the system differ" for the other three.

A state that needs a person (Failed, a channel that needs reconnecting) has a
square dot, so that it does not rest on colour alone.

## Patterns

- **Page header.** Title (`text-title md:text-display`), one line of secondary text
  under it, actions on the right. Times are announced once, here: "Times in
  Europe/Copenhagen."
- **Card.** `rounded-xl bg-card shadow-xs`, padding `p-5.5`. A label in secondary
  text (15px) names a small card; `text-heading` names a section.
- **Row.** `rounded-lg bg-card shadow-xs`, one line of facts: mono time, chip,
  text, action.
- **Channel tag.** `rounded-sm bg-secondary px-2.5 py-1 text-sm`.
- **Segmented control.** Track `rounded-lg bg-accent p-1`; the chosen segment is
  `rounded-[10px] bg-card shadow-xs`, 44px high.
- **Gunmetal panel.** `rounded-2xl bg-panel text-panel-foreground`. One per page
  at most. It holds what is loaded: the next firing with its countdown and fuse.
- **Failed.** A Paper card with a 2px Failed border all the way round, the Failed
  chip, one bold line that says what did not happen ("Tue 12:00 on LinkedIn was
  not published"), the reason in plain language, and one gunmetal button.
- **Advice and checks.** A row with a small filled tag: "OK" on Harbour green,
  "Check" on dark brass with the advice surface behind the row.
- **Times, counts, ids.** Always `font-mono`.
- **Table of numbers.** Inside a card, in a wrapper with `overflow-x-auto` and a
  `min-w` on the table, so that a phone scrolls the table and not the page. A
  `caption` (`sr-only` when the heading above says the same), column headings in
  secondary text at `text-sm` and normal weight, `scope` on every heading, rows
  divided by `border-t border-border`, numbers right-aligned in `font-mono`. No
  zebra stripes, no coloured cells. Insights use it; nothing is drawn as a chart.
- **A number that was not given.** A dash in secondary text, drawn by
  `MetricValue` (`resources/js/components/insights`), with the reason as text
  for a screen reader and on hover. Never a zero. The page says once, in a
  sentence, what a dash means. An average that is made of fewer posts than its
  row counts says so under the number ("from 3 of 5").
- **Where numbers stand.** One sentence from `resources/js/lib/insights.ts`:
  when they were last checked, when the first reading is expected, or why
  there are none and what to do. It is secondary text, not an alert: a number
  that is missing is not a failure.

## Voice

Plain and direct. No contractions, short hyphens only, no marketing filler. Say
what happens, in the order it happens. Examples from the guide:

- "Nothing is published until you approve it."
- "Unapproved posts are never published."
- "Tue 12:00 on LinkedIn was not published"
- "LinkedIn asked you to sign in again."
- "Approved. Fires Wed 12:00."
- "Next firing, Wed 12:00"
- "Firing time, Europe/Copenhagen"
- "Write something first" and "Too long for LinkedIn" as the label of a button
  that cannot be pressed yet
- "Every draft starts here. Change the brief and next week changes with it."
- "Drafts wait for your approval. Nothing is published from this page."

Words to keep: a post has a **firing time** and **fires**; the week is **loaded**;
a post that went out is **published**; one that did not is **failed** and "was not
published". Do not use the cannon for state names.

"Voice rules advise. Only platform limits block." Advice is shown in dark brass
and never disables a button.

## Accessibility minimums

- Every target is at least 44 by 44px, including checkboxes (wrap them in a label
  of that size) and icon buttons.
- Text contrast is at least 4.5:1, large text and the boundary of a control at
  least 3:1. The pairs in use are checked; a new pair must be checked too.
- Focus is always visible: a 3px ring in the `--ring` colour (Gunmetal, or Powder
  in the dark theme). The guide's 25 percent ring is 1.7:1 and is not used.
- Colour is never the only signal: a state has a word, a problem has a sentence.
- Motion is small and stops under `prefers-reduced-motion`.
- The countdown is not announced every second. The firing time next to it is text.

## Not allowed

From `CLAUDE.md`, and these win over the guide:

- Numbered menus
- Small letter-spaced capital labels
- Coloured accent side-bars on cards (a full 2px border is the Failed treatment)
- Gradient hero blocks
- Emoji as icons

From the guide:

- A second red action in a view
- Brass as text, or as the only signal on a light surface
- Fonts or other assets loaded from a third party
- A raw colour, radius or font size on a page when a token exists
- Anything ticked or approved for the user: no pre-ticked box, no "approve all"

## Where the guide and the system differ

The guide was drawn before M3 was built. These are open and are the owner's to
decide. Until then the interface follows the system and borrows only the look.

- **Unit of approval.** The guide approves each channel version by itself
  ("1 of 2 approved", "Approve both"). The system approves the post (ADR 0009).
- **Approve all.** The guide's red action on the queue is "Approve all N drafts".
  ADR 0010 rules that out. The red action is "Approve N selected".
- **Four states or seven.** The guide's Approved means approved with a firing
  time. The system separates `approved` and `scheduled`, and also has
  `publishing` and `rejected`. All seven keep their own word.
- **What blocks an approval.** The guide: only the platform limit. The system also
  blocks an empty text, a channel that cannot publish, a link without a title
  (or with a title or description that is too long) on a platform that builds
  no preview, and a planned time after the access to a channel ends.
- **Fixing a failed post.** The guide has one button that reconnects and
  publishes. The system opens the post first, because sending again can make a
  duplicate.
- **Slots.** The guide calls them firing time slots. A slot only proposes a time
  for a draft, so the pages say "planned time"; "firing time" stays for a post
  that is approved.
- **Built in M5.** The texts of a post side by side, one column per channel,
  with "Copy text from" between them (ADR 0014). The unit of approval is still
  the post.
- **Built in M4.** Brief, slots, idea and pillar, and "Why it was drafted this
  way".
- **Not built yet.** Voice rule checks, photos and alt text, the platform
  preview, the day strip, reject with undo, the one-card review on a phone,
  channel status in the sidebar, the draft count in the navigation and Library.
- **Built in M6.** Insights, as tables of numbers and sentences, without
  charts. It is in the navigation between Ideas and Channels: what was written,
  how it did, then where it goes.
- **Density.** `CLAUDE.md` asks for a calm, dense, tool-like interface. The guide
  sets body text at 17px, controls at 46px and cards at 20px radius. The guide's
  values are used.
