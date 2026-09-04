# Lottery Intelligence Components

React/TypeScript components for displaying EuroMillions lottery data in the WINDELS AI Workforce platform.

## Components

### `LotteryWidget`

Basic lottery widget showing jackpot, countdown timer, and recent results.

**Usage:**
```tsx
import { LotteryWidget } from '@/components/lottery';

<LotteryWidget 
  dashboard={dashboardData} 
  loading={false} 
/>
```

**Props:**
- `dashboard?: LiDashboard` - Lottery dashboard data from API
- `loading?: boolean` - Loading state

### `EuroMillionsWidget`

Enhanced EuroMillions widget with AI number generator, hot/cold statistics, and comprehensive draw data.

**Usage:**
```tsx
import { EuroMillionsWidget } from '@/components/lottery';

<EuroMillionsWidget 
  dashboard={dashboardData}
  loading={false}
  hotNumbers={[7, 19, 23, 38, 44]}
  coldNumbers={[3, 11, 27, 35, 49]}
/>
```

**Props:**
- `dashboard?: LiDashboard` - Lottery dashboard data from API
- `loading?: boolean` - Loading state
- `hotNumbers?: number[]` - Most frequent numbers
- `coldNumbers?: number[]` - Least frequent numbers

## Types

### `LiDashboard`

```typescript
interface LiDashboard {
  status: string;
  jackpot: number | null;
  nextEstimated: string | null;
  lastDraw: LiDraw | null;
  recentDraws: LiDraw[];
  imported: number;
  myTicketsCount: number;
  rules: {
    mains: number;
    mainMax: number;
    stars: number;
    starMax: number;
  };
  lotteries: Array<{
    code: string;
    name: string;
  }>;
}
```

### `LiDraw`

```typescript
interface LiDraw {
  id: number;
  lottery_code: string;
  draw_date: string;
  mainNumbers: number[];
  bonusNumbers?: number[];
  stars?: number[];
  jackpot?: number;
  winners: number;
}
```

## API Integration

Fetch data from the backend API:

```typescript
const response = await fetch('/api/lottery/dashboard');
const dashboard: LiDashboard = await response.json();
```

## Features

- ✅ Real-time jackpot display with formatted currency (€M/K)
- ✅ Live countdown timer to next draw
- ✅ Recent draw results with main numbers and stars
- ✅ AI-powered number generator (random balanced selection)
- ✅ Hot/cold number statistics
- ✅ Quick action links to full lottery intelligence
- ✅ Responsive design for all screen sizes
- ✅ WINDELS design system compliance
- ✅ Loading and empty states
- ✅ TypeScript type safety

## Styling

Components use Tailwind CSS classes following the WINDELS design system:
- Gradient backgrounds (amber/orange for lottery theme)
- Glass morphism effects
- Color-coded number balls (amber for main, cyan for stars)
- Responsive grid layouts
- Dark mode optimized

## Notes

- **No Predictions**: All statistics are historical observations, not future predictions
- **Verified Data**: Only uses verified draw data from official sources
- **Honest AI**: Number generation is random, not predictive
- **RBAC**: Components respect user permissions for lottery features
