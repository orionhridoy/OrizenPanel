import { IsInt, IsOptional, Max, Min } from 'class-validator';
import { Type } from 'class-transformer';

/** Shared pagination query (?limit=&offset=) for list endpoints. */
export class PageDto {
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  @Max(100)
  limit = 25;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  offset = 0;
}
