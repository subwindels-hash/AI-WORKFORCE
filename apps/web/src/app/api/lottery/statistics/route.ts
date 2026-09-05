import { NextResponse } from 'next/server';

/**
 * Synthetic hot/cold numbers for demonstration purposes.
 * In production, this would query the MySQL database for real frequency data.
 */
const syntheticHotCold = { hot: [8, 15, 23, 31, 42], cold: [3, 14, 27, 33, 46] };

export const GET = () => NextResponse.json(syntheticHotCold);